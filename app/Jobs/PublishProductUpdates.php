<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\KafkaFlushFailedException;
use App\Models\Partner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Config;
use Jobcloud\Kafka\Message\KafkaProducerMessage;
use Jobcloud\Kafka\Producer\KafkaProducerInterface;
use Throwable;

// about six hours of retries, so a broker restart or upgrade only delays the messages
#[Tries(75)]
#[Backoff(10, 60, 300)]
#[DeleteWhenMissingModels]
final class PublishProductUpdates implements ShouldQueue
{
    use Queueable;

    private const int FLUSH_TIMEOUT_MILLISECONDS = 10_000;

    /**
     * @param  list<string>  $skus
     */
    public function __construct(
        public Partner $partner,
        public array $skus,
        public int $feedImportId,
    ) {}

    public function handle(KafkaProducerInterface $producer): void
    {
        $partnerCode = $this->partner->code;
        $topic = Config::string('feed.kafka_topic');

        // read back instead of carrying the payload, so a delayed job sends the current row
        $products = $this->partner->products()
            ->whereIn('sku', $this->skus)
            ->get();

        try {
            foreach ($products as $product) {
                $producer->produce(
                    KafkaProducerMessage::create($topic, RD_KAFKA_PARTITION_UA)
                        ->withKey(sprintf('%s:%s', $partnerCode, $product->sku))
                        ->withBody(json_encode([
                            'product_id' => $product->id,
                            'partner' => $partnerCode,
                            'sku' => $product->sku,
                            'name' => $product->name,
                            'price' => $product->price,
                            'currency' => $product->currency,
                            'stock' => $product->stock,
                            'version' => $product->version,
                            'feed_import_id' => $this->feedImportId,
                        ], JSON_THROW_ON_ERROR)),
                );
            }

            // delivery reports are read here, so a failed message fails this job and not a later one
            $flushErrorCode = $producer->flush(self::FLUSH_TIMEOUT_MILLISECONDS);
            if ($flushErrorCode !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                throw new KafkaFlushFailedException(sprintf('Flushing %d product messages failed: %s.', $products->count(), rd_kafka_err2str($flushErrorCode)));
            }
        } catch (Throwable $throwable) {
            // a fatal idempotence error leaves the producer unusable for the worker's lifetime; the retry builds a new one
            app()->forgetInstance(KafkaProducerInterface::class);

            throw $throwable;
        }
    }
}
