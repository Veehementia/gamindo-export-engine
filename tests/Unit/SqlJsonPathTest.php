<?php

namespace Tests\Unit;

use App\Services\Export\SqlJsonPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SqlJsonPathTest extends TestCase
{
    public function test_detects_json_paths(): void
    {
        $this->assertTrue(SqlJsonPath::isJsonPath('payload.language'));
        $this->assertFalse(SqlJsonPath::isJsonPath('email'));
    }

    public function test_parses_column_and_segments(): void
    {
        [$column, $segments] = SqlJsonPath::parse('payload.a.b');
        $this->assertSame('payload', $column);
        $this->assertSame(['a', 'b'], $segments);
    }

    public function test_builds_mysql_expression(): void
    {
        $expr = SqlJsonPath::expression('mysql', 'payload', ['language']);
        $this->assertSame('JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$."language"\'))', $expr);
    }

    public function test_builds_sqlite_expression(): void
    {
        $expr = SqlJsonPath::expression('sqlite', 'payload', ['language']);
        $this->assertSame('json_extract(`payload`, \'$."language"\')', $expr);
    }

    public function test_rejects_unsafe_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqlJsonPath::parse('payload.foo`; DROP TABLE events;--');
    }
}
