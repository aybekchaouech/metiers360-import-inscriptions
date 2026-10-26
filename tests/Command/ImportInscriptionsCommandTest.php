<?php

namespace App\Tests\Command;

use App\Tests\Support\FakeFormApi;
use App\Tests\Support\GetsServices;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What cron and the operations team see: the output and the exit code.
 */
final class ImportInscriptionsCommandTest extends KernelTestCase
{
    use GetsServices;
    use ResetsDatabase;

    private FakeFormApi $api;
    private CommandTester $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->resetDatabase(self::service(EntityManagerInterface::class));
        $this->api = self::service(FakeFormApi::class);
        $this->api->useDataset('v1');
        $this->command = new CommandTester((new Application($kernel))->find('app:inscriptions:import'));
    }

    public function testSuccessfulImportExitsWithZero(): void
    {
        $exitCode = $this->command->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Import #1 completed', $this->command->getDisplay());
    }

    public function testFailedImportExitsWithNonZeroSoThatCronNotices(): void
    {
        $this->api->breakPage(2);

        $exitCode = $this->command->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Import #1 failed: Page 2', $this->command->getDisplay());
    }

    public function testRejectedSubmissionsAreListed(): void
    {
        $this->api->addSubmission(['id' => 'sub_no_session', 'status' => 'submitted', 'created_at' => '2026-10-20T10:00:00Z', 'answers' => ['email' => 'nora@example.com']]);

        $exitCode = $this->command->execute([]);
        $display = $this->command->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 submission(s) could not be imported', $display);
        self::assertStringContainsString('sub_no_session', $display);
        self::assertStringContainsString('nora@example.com', $display);
    }
}
