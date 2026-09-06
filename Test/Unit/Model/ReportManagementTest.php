<?php

declare(strict_types=1);

namespace Angeo\AeoBrandVisibility\Test\Unit\Model;

use Angeo\AeoBrandVisibility\Api\Data\VisibilitySummaryInterfaceFactory;
use Angeo\AeoBrandVisibility\Model\AuditResult;
use Angeo\AeoBrandVisibility\Model\AuditResultRepository;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Angeo\AeoBrandVisibility\Model\ReportManagement;
use Angeo\AeoBrandVisibility\Model\VisibilitySummary;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class ReportManagementTest extends TestCase
{
    /** @var AuditResultRepository&\PHPUnit\Framework\MockObject\MockObject */
    private AuditResultRepository $repository;

    private ReportManagement $management;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditResultRepository::class);

        $factory = $this->createMock(VisibilitySummaryInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn() => new VisibilitySummary());

        $this->management = new ReportManagement(
            $this->repository,
            $factory,
            new Json(),
        );
    }

    public function testGetLatestMapsRecordToSummary(): void
    {
        // A real AuditResult (magic getters + real decode) exercises the full
        // mapping path rather than mocking every accessor.
        $json   = new Json();
        $record = new AuditResult(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $json,
            null,
            null,
            [
                'overall_score'  => 72,
                'grade'          => 'B',
                'brand_name'     => 'Angeo Ceramics',
                'store_id'       => 2,
                'created_at'     => '2026-07-03 05:00:00',
                'share_of_voice' => $json->serialize(['Angeo Ceramics' => 40.0, 'RivalShop' => 80.0]),
            ]
        );

        $this->repository->method('getLatestForStore')->with(2)->willReturn($record);

        $summary = $this->management->getLatest(2);

        $this->assertSame(72, $summary->getScore());
        $this->assertSame('B', $summary->getGrade());
        $this->assertSame('Angeo Ceramics', $summary->getBrandName());
        $this->assertSame(2, $summary->getStoreId());
        $this->assertSame('2026-07-03 05:00:00', $summary->getGeneratedAt());
        $this->assertStringContainsString('RivalShop', $summary->getShareOfVoiceJson());
    }

    public function testGetLatestThrowsWhenNoRecord(): void
    {
        $this->repository->method('getLatestForStore')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);
        $this->management->getLatest(null);
    }
}
