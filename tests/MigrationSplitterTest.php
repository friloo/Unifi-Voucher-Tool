<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../updater/MigrationRunner.php';

final class MigrationSplitterTest extends TestCase
{
    private function split(string $sql): array
    {
        $rc = new \ReflectionClass(\Updater\MigrationRunner::class);
        $inst = $rc->newInstanceWithoutConstructor();
        $m = $rc->getMethod('splitStatements');
        $m->setAccessible(true);
        $parts = $m->invoke($inst, $sql);
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }

    public function testIgnoresSemicolonsInStringsAndComments(): void
    {
        $sql = "INSERT INTO t (a) VALUES (\";semi;colon\"); -- comment; not split\n"
             . "CREATE TABLE x (id INT); /* block ; comment */ INSERT INTO y VALUES (1);";
        $parts = $this->split($sql);
        $this->assertCount(3, $parts);
    }

    public function testSingleStatement(): void
    {
        $parts = $this->split("ALTER TABLE users ADD COLUMN foo INT");
        $this->assertCount(1, $parts);
    }

    public function testIgnorableErrorDetection(): void
    {
        $rc = new \ReflectionClass(\Updater\MigrationRunner::class);
        $inst = $rc->newInstanceWithoutConstructor();
        $this->assertTrue($inst->isIgnorableSqlError('Duplicate column name "x"', 'mysql'));
        $this->assertTrue($inst->isIgnorableSqlError('Table already exists', 'mysql'));
        $this->assertFalse($inst->isIgnorableSqlError('Syntax error near FROM', 'mysql'));
    }
}
