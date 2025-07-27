<?php

namespace App\Http\Requests;

use App\Services\DebrickedApiService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

class DependencyUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Update this to include authorization logic if needed
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => 'required|array', // Expecting an array of files
            'files.*' => [
                'file',
                'max:20480', // Max file size: 20MB
                function ($attribute, $value, $fail) {
                    $regexList = $this->getSupportedFileFormatsRegex();

                    // Get the file name
                    $filename = $value->getClientOriginalName();

                    // Check if the file name matches any of the regex patterns
                    if (! $this->matchesAnyRegex($filename, $regexList)) {
                        $fail("The file $filename does not match any of the supported file formats.");
                    }
                },
            ],
            'commit_name' => 'required|string',
            'repository_name' => 'required|string',
        ];
    }

    /**
     * Retrieve the supported file formats regex list.
     *
     * @return array<string>
     */
    protected function getSupportedFileFormatsRegex(): array
    {
        $debrickedApiService = new DebrickedApiService();
        $supportedFileFormats = $debrickedApiService->getSupportedFileFormats();

        return $debrickedApiService->extractRegexPatterns($supportedFileFormats);
    }

    /**
     * Check if the given filename matches any of the regex patterns.
     *
     * @param  array<string>  $regexList
     */
    protected function matchesAnyRegex(string $filename, array $regexList): bool
    {
        foreach ($regexList as $regex) {
            if (preg_match("/$regex/", $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws HttpResponseException
     */
    public function failedValidation(Validator $validator): void
    {
        // Custom response for validation errors
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
