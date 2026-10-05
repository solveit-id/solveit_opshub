<?php

namespace Tests\Unit;

use App\Infrastructure\Backup\SftpBundleInspection;
use App\Infrastructure\Backup\SftpFileBundle;
use App\Infrastructure\Connectors\ConnectorFailure;
use Tests\TestCase;

class SftpBundleInspectionTest extends TestCase
{
    private function bundle(string $path = 'safe/file.txt'): array
    {
        $entry = ['root_index' => 0, 'path' => $path, 'bytes' => 3, 'mtime' => 101];
        $header = fn ($value) => pack('N', strlen($json = json_encode($value))).$json;
        $bytes = SftpFileBundle::MAGIC.$header(['kind' => 'file', ...$entry]).'abc'.hash('sha256', 'abc', true).$header(['kind' => 'end', 'file_count' => 1]);
        $manifest = ['files' => [[...$entry, 'sha256' => hash('sha256', 'abc'), 'status' => 'read']], 'file_count' => 1, 'bytes' => 3, 'errors' => []];

        return [$bytes, $manifest];
    }

    public function test_arbitrary_chunk_boundaries_and_mysql_object_key_order_preserve_typed_manifest(): void
    {
        [$bytes, $manifest] = $this->bundle();
        ksort($manifest['files'][0]);
        $inspection = new SftpBundleInspection(100);
        foreach (str_split($bytes, 1) as $chunk) {
            $inspection->accept($chunk);
        }
        $inspection->finish($manifest);
        $this->addToAssertionCount(1);
    }

    public function test_missing_required_entry_metadata_type_mismatch_checksum_traversal_or_trailing_bytes_fail_content(): void
    {
        foreach (['missing', 'type', 'checksum', 'path', 'truncated', 'trailing', 'byte_limit'] as $case) {
            [$bytes, $manifest] = $this->bundle($case === 'path' ? '../outside' : 'safe/file.txt');
            if ($case === 'missing') {
                $manifest['files'][] = [...$manifest['files'][0], 'path' => 'required-missing'];
                $manifest['file_count']++;
            }
            if ($case === 'type') {
                $manifest['files'][0]['bytes'] = '3';
            }
            if ($case === 'checksum') {
                $position = strpos($bytes, 'abc');
                $bytes[$position] = 'x';
            }
            if ($case === 'truncated') {
                $bytes = substr($bytes, 0, -3);
            }
            if ($case === 'trailing') {
                $bytes .= 'x';
            }
            $inspection = new SftpBundleInspection($case === 'byte_limit' ? 2 : 100);
            try {
                $inspection->accept($bytes);
                $inspection->finish($manifest);
                $this->fail('Invalid content passed: '.$case);
            } catch (ConnectorFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
