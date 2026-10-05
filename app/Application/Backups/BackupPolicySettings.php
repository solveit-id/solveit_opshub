<?php

namespace App\Application\Backups;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BackupPolicySettings
{
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'required_scopes' => ['required', 'array', 'min:1', 'max:3'], 'required_scopes.*' => ['required', 'distinct', 'in:files,database,full_account'],
            'included_paths' => ['required', 'array', 'min:1', 'max:100'], 'included_paths.*' => ['array:root_index,path'],
            'included_paths.*.root_index' => ['required', 'integer', 'min:0', 'max:19'], 'included_paths.*.path' => ['present', 'nullable', 'string', 'max:512'],
            'excluded_paths' => ['present', 'array', 'max:100'], 'excluded_paths.*' => ['string', 'max:512'],
            'timezone' => ['required', 'timezone:all'], 'daily_at' => ['required', 'date_format:H:i'],
            'jitter_minutes' => ['required', 'integer', 'min:0', 'max:30'], 'rpo_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'destination_reference' => ['required', 'string', 'regex:/^storage:[a-zA-Z0-9_-]{1,100}$/D'],
            'retention' => ['required', 'array:daily,weekly,monthly'], 'retention.daily' => ['required', 'integer', 'min:1', 'max:365'],
            'retention.weekly' => ['required', 'integer', 'min:1', 'max:104'], 'retention.monthly' => ['required', 'integer', 'min:1', 'max:120'],
            'verification' => ['required', 'in:transport_verified,content_verified,restore_verified'],
            'cleanup_temporary_source' => ['sometimes', 'boolean'],
            'max_bytes' => ['required', 'integer', 'min:1', 'max:1099511627776'],
            'bytes_per_second' => ['required', 'integer', 'min:65536', 'max:104857600'], 'max_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
        ])->validate();
        // Laravel normalizes an empty root-relative path to null on HTTP requests.
        foreach ($data['included_paths'] as &$included) {
            $included['root_index'] = (int) $included['root_index'];
            $included['path'] ??= '';
        }
        unset($included);
        $data['cleanup_temporary_source'] = (bool) ($data['cleanup_temporary_source'] ?? false);
        foreach (['jitter_minutes', 'rpo_hours', 'max_bytes', 'bytes_per_second', 'max_seconds'] as $key) {
            $data[$key] = (int) $data[$key];
        }
        foreach ($data['retention'] as &$count) {
            $count = (int) $count;
        }
        unset($count);
        foreach ([...array_column($data['included_paths'], 'path'), ...$data['excluded_paths']] as $path) {
            if (str_starts_with($path, '/') || preg_match('/[\\\\\x00-\x1f\x7f%*?]/', $path) || str_contains($path, '//') || ($path !== '' && str_ends_with($path, '/'))
                || array_intersect(explode('/', $path), ['.', '..'])) {
                throw ValidationException::withMessages(['paths' => 'Paths harus canonical relative, tanpa traversal/symlink/glob.']);
            }
        }

        return $data;
    }

    public function defaults(): array
    {
        return ['required_scopes' => ['files', 'database'], 'included_paths' => [['root_index' => 0, 'path' => '']], 'excluded_paths' => [],
            'timezone' => 'Asia/Jakarta', 'daily_at' => '02:00', 'jitter_minutes' => 30, 'rpo_hours' => 30,
            'destination_reference' => 'storage:unconfigured', 'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 3],
            'verification' => 'transport_verified', 'cleanup_temporary_source' => false, 'max_bytes' => 10737418240, 'bytes_per_second' => 1048576, 'max_seconds' => 3600];
    }
}
