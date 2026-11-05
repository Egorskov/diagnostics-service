<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\UI\Console\Emulator\EmulatedStream;
use App\UI\Console\Emulator\MessengerEventPublisher;
use App\UI\Console\Emulator\Profile;
use App\UI\Console\Emulator\Scenario;
use App\UI\Console\Emulator\ScenarioGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Эмулятор входящих событий (SPEC §10, шаг 8 MVP): история одного ученика в exchange продюсеров.
 */
#[AsCommand(name: 'app:emulate-events', description: 'Сгенерировать и опубликовать поток входящих событий одного ученика')]
final class EmulateEventsCommand extends Command
{
    private const API = 'http://localhost:8080/api/v1';

    public function __construct(
        private readonly ScenarioGenerator $generator,
        private readonly MessengerEventPublisher $publisher,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $profiles = implode(', ', array_column(Profile::cases(), 'value'));
        $this
            // аргумент, а не --profile: эту опцию Symfony занимает под профайлер команд
            ->addArgument('profile', InputArgument::OPTIONAL, 'Поведение ученика: '.$profiles, Profile::Steady->value)
            ->addOption('days', null, InputOption::VALUE_REQUIRED, \sprintf('Длина истории в днях, %d–%d', Scenario::MIN_DAYS, Scenario::MAX_DAYS), '14')
            ->addOption('student', null, InputOption::VALUE_REQUIRED, 'UUID ученика; по умолчанию — из seed')
            ->addOption('mentor', null, InputOption::VALUE_REQUIRED, 'UUID ментора; по умолчанию — из seed')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed генератора: тот же seed — те же события и event_id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Вывести события в stdout как NDJSON и ничего не публиковать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $scenario = $this->scenario($input);
            // весь поток строится до первой публикации: ошибка профиля не оставит полуистории
            $stream = $this->generator->generate($scenario, $this->clock->now());
        } catch (\InvalidArgumentException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::INVALID;
        }

        if ($input->getOption('dry-run')) {
            foreach ($stream->events as $event) {
                $output->writeln($event->json(), OutputInterface::OUTPUT_RAW);
            }

            return Command::SUCCESS;
        }

        foreach ($stream->events as $event) {
            $this->publisher->publish($event);
        }
        $this->report($io, $scenario, $stream);

        return Command::SUCCESS;
    }

    private function scenario(InputInterface $input): Scenario
    {
        $argument = $input->getArgument('profile');
        $profile = Profile::tryFrom(\is_string($argument) ? $argument : '')
            ?? throw new \InvalidArgumentException(\sprintf('Unknown profile, expected one of: %s', implode(', ', array_column(Profile::cases(), 'value'))));
        $seed = self::string($input, 'seed');

        return new Scenario(
            $profile,
            self::integer(self::string($input, 'days') ?? '', 'days'),
            null === $seed ? random_int(0, \PHP_INT_MAX) : self::integer($seed, 'seed'),
            self::uuid($input, 'student'),
            self::uuid($input, 'mentor'),
        );
    }

    private function report(SymfonyStyle $io, Scenario $scenario, EmulatedStream $stream): void
    {
        $io->title(\sprintf('Опубликовано событий: %d', \count($stream->events)));
        $io->definitionList(
            ['Ученик' => $stream->studentId],
            ['Путь' => $stream->pathId],
            ['Ментор' => $stream->mentorId],
            ['Профиль' => $scenario->profile->value],
            ['Дней' => (string) $scenario->days],
            ['Seed' => (string) $scenario->seed],
        );
        $io->table(['event_type', 'Событий'], array_map(null, array_keys($stream->countsByType()), array_values($stream->countsByType())));

        $student = self::token($stream->studentId, 'student');
        $mentor = self::token($stream->mentorId, 'mentor');
        $io->section('Read-API (заглушечные токены, ADR 0006)');
        $io->writeln([
            \sprintf("curl -H 'Authorization: Bearer %s' %s/students/%s/summary", $student, self::API, $stream->studentId),
            \sprintf("curl -H 'Authorization: Bearer %s' %s/mentor/students", $mentor, self::API),
        ]);
        $io->note(match ($scenario->profile) {
            Profile::Steady => 'События обработает make consume; сигналов не ожидается.',
            Profile::Stuck => 'diagnostics.task.stuck придёт при обработке heartbeat, как только make consume дочитает очередь.',
            Profile::Inactive => 'diagnostics.student.inactive придёт на ближайшей ежечасной проверке Scheduler (make consume).',
            Profile::Behind => 'diagnostics.weekly_goal.behind придёт на ежечасной проверке после момента weekly_goal_check текущей недели (по умолчанию чт 18:00 Europe/Belgrade).',
        });
    }

    private static function string(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }

    private static function integer(string $value, string $name): int
    {
        if (!preg_match('/^\d+$/', $value) || (string) (int) $value !== $value) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a non-negative integer, got "%s"', $name, $value));
        }

        return (int) $value;
    }

    private static function uuid(InputInterface $input, string $name): ?string
    {
        $value = self::string($input, $name);
        if (null !== $value && !Uuid::isValid($value)) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a UUID, got "%s"', $name, $value));
        }

        return null === $value ? null : strtolower($value);
    }

    /**
     * JWT без подписи для StubJwtAuthenticator: base64url заголовка и payload (ADR 0006).
     */
    private static function token(string $subject, string $role): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode(['sub' => $subject, 'role' => $role]).'.emulator';
    }
}
