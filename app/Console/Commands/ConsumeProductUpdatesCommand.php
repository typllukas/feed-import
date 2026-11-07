<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PriceUpdateOutcome;
use App\Services\PriceHistoryRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Jobcloud\Kafka\Consumer\KafkaConsumerBuilderInterface;
use Jobcloud\Kafka\Consumer\KafkaHighLevelConsumerInterface;
use Jobcloud\Kafka\Exception\KafkaConsumerCommitException;
use Jobcloud\Kafka\Exception\KafkaConsumerConsumeException;
use Jobcloud\Kafka\Exception\KafkaConsumerEndOfPartitionException;
use Jobcloud\Kafka\Exception\KafkaConsumerTimeoutException;
use Jobcloud\Kafka\Message\KafkaConsumerMessageInterface;
use JsonException;
use LogicException;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[Signature('product-updates:consume {--max-time=10 : Seconds to keep reading before stopping}')]
#[Description('Read product-updates from Kafka into the price history')]
final class ConsumeProductUpdatesCommand extends Command
{
    private const int CONSUME_TIMEOUT_MILLISECONDS = 1_000;

    private const array TRANSIENT_CONSUME_ERRORS = [RD_KAFKA_RESP_ERR__TRANSPORT, RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN];

    private const array MESSAGE_RULES = [
        'product_id' => ['required', 'integer:strict', 'min:1'],
        'partner' => ['required', 'string', 'max:32'],
        'sku' => ['required', 'string', 'max:64'],
        'version' => ['required', 'integer:strict', 'min:1'],
        // what decimal(10,2) holds; MySQL rounds a third decimal without an error
        'price' => ['required', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/D'],
        'currency' => ['required', 'string', 'size:3'],
        'feed_import_id' => ['required', 'integer:strict', 'min:1'],
    ];

    public function handle(PriceHistoryRecorder $priceHistoryRecorder, KafkaConsumerBuilderInterface $consumerBuilder): int
    {
        $maximumSeconds = filter_var($this->option('max-time'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($maximumSeconds === false) {
            $this->error('--max-time must be a whole number of seconds, at least 1.');

            return self::FAILURE;
        }

        $consumer = $consumerBuilder->withRebalanceCallback($this->assignRebalancedPartitions(...))->build();
        if (! $consumer instanceof KafkaHighLevelConsumerInterface) {
            throw new LogicException('The builder makes a high-level consumer unless told otherwise.');
        }

        /*
         * no close(): php-rdkafka 6.0.5 closes without destroying, and the live handle's threads crash PHP at exit;
         * released when handle() returns, the consumer leaves the group and is destroyed
         */
        return $this->consumeUntil(time() + $maximumSeconds, $consumer, Config::string('feed.kafka_topic'), $priceHistoryRecorder);
    }

    private function consumeUntil(int $stopTimestamp, KafkaHighLevelConsumerInterface $consumer, string $topic, PriceHistoryRecorder $priceHistoryRecorder): int
    {
        $consumer->subscribe();
        $consumedMessageCount = 0;

        while (time() < $stopTimestamp) {
            try {
                $message = $consumer->consume(self::CONSUME_TIMEOUT_MILLISECONDS);
            } catch (KafkaConsumerTimeoutException|KafkaConsumerEndOfPartitionException) {
                continue;
            } catch (KafkaConsumerConsumeException $exception) {
                $this->error($exception->getMessage());
                if (in_array($exception->getCode(), self::TRANSIENT_CONSUME_ERRORS, true)) {
                    continue;
                }

                return self::FAILURE;
            }

            try {
                $outcome = $this->applyMessage($message, $priceHistoryRecorder);
            } catch (JsonException|ValidationException $exception) {
                /*
                 * only our own job writes this topic, so a bad message is a bug, not data for a dead-letter topic;
                 * stop uncommitted, and the message is read again after the fix
                 */
                $messageBody = $message->getBody();
                $payloadExcerpt = $this->escapeForTerminal(Str::limit(is_string($messageBody) ? $messageBody : '', 500));
                $this->error(sprintf('Partition %d offset %d is not a product update (payload %s): %s', $message->getPartition(), $message->getOffset(), $payloadExcerpt, $exception->getMessage()));

                return self::FAILURE;
            } catch (QueryException $exception) {
                $this->error(sprintf('Partition %d offset %d could not be applied: %s', $message->getPartition(), $message->getOffset(), $this->escapeForTerminal($exception->getMessage())));

                return self::FAILURE;
            }

            $messageKey = $message->getKey();
            $this->line(sprintf('Partition %d offset %d %s: %s', $message->getPartition(), $message->getOffset(), $this->escapeForTerminal(is_string($messageKey) ? $messageKey : ''), $outcome->value));
            $consumedMessageCount++;

            // after the recorder's transaction, and async: a crash or a lost commit only replays the message, which the version check skips
            try {
                $consumer->commitAsync($message);
            } catch (KafkaConsumerCommitException $exception) {
                $this->warn(sprintf('Commit of partition %d offset %d failed; the next commit on this partition covers it, and a redelivery is skipped by version: %s', $message->getPartition(), $message->getOffset(), $exception->getMessage()));
            }
        }

        $this->line(sprintf('Consumed %d %s from %s.', $consumedMessageCount, Str::plural('message', $consumedMessageCount), $topic));

        return self::SUCCESS;
    }

    private function applyMessage(KafkaConsumerMessageInterface $message, PriceHistoryRecorder $priceHistoryRecorder): PriceUpdateOutcome
    {
        $messageBody = $message->getBody();
        $decodedBody = json_decode(is_string($messageBody) ? $messageBody : '', true, 512, JSON_THROW_ON_ERROR);

        /** @var array{product_id: int, partner: string, sku: string, version: int, price: string, currency: string, feed_import_id: int} $productUpdate */
        $productUpdate = Validator::make(is_array($decodedBody) ? $decodedBody : [], self::MESSAGE_RULES)->validate();

        return $priceHistoryRecorder->record(
            productId: $productUpdate['product_id'],
            partnerCode: $productUpdate['partner'],
            sku: $productUpdate['sku'],
            version: $productUpdate['version'],
            price: $productUpdate['price'],
            currency: $productUpdate['currency'],
            feedImportId: $productUpdate['feed_import_id'],
        );
    }

    /**
     * A closing style tag in partner data makes the console formatter throw, and control bytes would reach the terminal
     */
    private function escapeForTerminal(string $text): string
    {
        return OutputFormatter::escape(addcslashes($text, "\0..\37\177"));
    }

    /**
     * With a rebalance callback set, librdkafka leaves the assignment to it: it must call assign() both ways.
     *
     * @param  list<TopicPartition>|null  $topicPartitions
     */
    private function assignRebalancedPartitions(KafkaConsumer $kafkaConsumer, int $errorCode, ?array $topicPartitions): void
    {
        $partitionList = implode(', ', array_map(fn (TopicPartition $topicPartition): int => $topicPartition->getPartition(), $topicPartitions ?? []));

        if ($errorCode === RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS) {
            $this->line(sprintf('Assigned partitions %s.', $partitionList));
            $kafkaConsumer->assign($topicPartitions);

            return;
        }

        $this->line(sprintf('Revoked partitions %s.', $partitionList));
        $kafkaConsumer->assign(null);
    }
}
