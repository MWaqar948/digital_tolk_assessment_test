<?php

namespace Tests\Unit;

use App\Helpers\TeHelper;
use Tests\TestCase;
use Carbon\Carbon;

class TeHelperTest extends TestCase
{
    public function testWillExpireAtWithin90Hours()
    {
        $due_time = Carbon::now()->addHours(85)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::parse($due_time)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $result);
    }

    public function testWillExpireAtWithin24Hours()
    {
        $due_time = Carbon::now()->addHours(20)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::parse($created_at)->addMinutes(90)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $result);
    }

    public function testWillExpireAtBetween24And72Hours()
    {
        $due_time = Carbon::now()->addHours(50)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::parse($created_at)->addHours(16)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $result);
    }

    public function testWillExpireAtMoreThan90Hours()
    {
        $due_time = Carbon::now()->addHours(100)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expected = Carbon::parse($due_time)->subHours(48)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $result);
    }
}
