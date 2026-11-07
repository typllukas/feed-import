<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\FeedHeader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

final class StoreFeedImportRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'mimes:csv,txt', 'max:10240', new FeedHeader],
        ];
    }

    public function getFeedFile(): UploadedFile
    {
        $feedFile = $this->file('file');
        if (! $feedFile instanceof UploadedFile) {
            throw new LogicException('The file rule guarantees a single uploaded file.');
        }

        return $feedFile;
    }
}
