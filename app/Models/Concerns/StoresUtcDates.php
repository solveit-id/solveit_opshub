<?php

namespace App\Models\Concerns;

use Carbon\Carbon;

trait StoresUtcDates
{
    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $value)) {
            return Carbon::parse($value, 'UTC');
        }

        return parent::asDateTime($value)->utc();
    }

    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }
}
