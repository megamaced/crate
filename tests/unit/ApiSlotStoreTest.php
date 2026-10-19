<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\Crate\Service\ApiSlotStore;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The first claim for a token finds no row and inserts one, and two workers
 * can both get that far. The loser's insert fails on the primary key, and that
 * has to read as "someone else made the row" and send it round again — on
 * every supported Nextcloud, which before 33 could surface Doctrine's own
 * exception rather than OCP\DB\Exception.
 *
 * The queries run against a scripted connection: each statement takes the
 * next scripted outcome for its kind (update, select, insert).
 */
#[AllowMockObjectsWithoutExpectations]
class ApiSlotStoreTest extends TestCase
{
    /** @var array<string, list<mixed>> scripted outcomes per statement kind */
    private array $script = [];

    /** @var list<string> statement kinds in the order they ran */
    private array $ran = [];

    public function testAFreeSlotIsTakenByTheUpdate(): void
    {
        $this->script = ['update' => [1]];

        self::assertNull($this->store()->claim('k', 1_000, 2_100));
        self::assertSame(['update'], $this->ran);
    }

    public function testABusySlotReportsWhenItFrees(): void
    {
        $this->script = ['update' => [0], 'select' => ['5000']];

        self::assertSame(5_000, $this->store()->claim('k', 1_000, 2_100));
    }

    public function testTheFirstClaimCreatesTheRow(): void
    {
        $this->script = ['update' => [0], 'select' => [false], 'insert' => [1]];

        self::assertNull($this->store()->claim('k', 1_000, 2_100));
        self::assertSame(['update', 'select', 'insert'], $this->ran);
    }

    public function testLosingTheInsertRaceToOcpDbExceptionRetries(): void
    {
        $this->assertLosingInsertRetries($this->ocpUniqueViolation());
    }

    public function testLosingTheInsertRaceToDoctrinesExceptionRetries(): void
    {
        $this->assertLosingInsertRetries($this->doctrineUniqueViolation());
    }

    public function testAnyOtherInsertFailurePropagates(): void
    {
        $this->script = ['update' => [0], 'select' => [false], 'insert' => [new \RuntimeException('disk full')]];

        $this->expectException(\RuntimeException::class);
        $this->store()->claim('k', 1_000, 2_100);
    }

    public function testHoldUntilCreatesTheRowAcrossTheSameRace(): void
    {
        $this->script = [
            'update' => [0, 0],
            'select' => [false, '61000'],
            'insert' => [$this->doctrineUniqueViolation()],
        ];

        $this->store()->holdUntil('k', 61_000);

        self::assertSame(['update', 'select', 'insert', 'update', 'select'], $this->ran);
    }

    private function assertLosingInsertRetries(\Exception $violation): void
    {
        // Worker B inserted between our read and our insert; its row is
        // already due, so the retried update takes it.
        $this->script = ['update' => [0, 1], 'select' => [false], 'insert' => [$violation]];

        self::assertNull($this->store()->claim('k', 1_000, 2_100));
        self::assertSame(['update', 'select', 'insert', 'update'], $this->ran);
    }

    private function ocpUniqueViolation(): DbException
    {
        return new class ('duplicate key') extends DbException {
            public function getReason(): ?int
            {
                return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
            }
        };
    }

    private function doctrineUniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException($this->createStub(DriverException::class), null);
    }

    private function store(): ApiSlotStore
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn() => $this->queryBuilder());
        return new ApiSlotStore($db);
    }

    private function queryBuilder(): IQueryBuilder
    {
        $kind = null;
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['update', 'select', 'insert'] as $method) {
            $qb->method($method)->willReturnCallback(function () use (&$kind, $method, &$qb) {
                $kind = $method;
                return $qb;
            });
        }
        foreach (['set', 'where', 'andWhere', 'from', 'values'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('createNamedParameter')->willReturn(':p');
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'lt', 'lte'] as $method) {
            $expr->method($method)->willReturn('cond');
        }
        $qb->method('expr')->willReturn($expr);

        $qb->method('executeStatement')->willReturnCallback(function () use (&$kind): int {
            return (int) $this->next((string) $kind);
        });
        $qb->method('executeQuery')->willReturnCallback(function () use (&$kind): IResult {
            $result = $this->createMock(IResult::class);
            $result->method('fetchOne')->willReturn($this->next((string) $kind));
            return $result;
        });

        return $qb;
    }

    private function next(string $kind): mixed
    {
        $this->ran[] = $kind;
        self::assertNotEmpty($this->script[$kind] ?? [], "no scripted outcome left for {$kind}");
        $outcome = array_shift($this->script[$kind]);
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }
        return $outcome;
    }
}
