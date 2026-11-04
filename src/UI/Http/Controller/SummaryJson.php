<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Api\MentorStudent;
use App\Application\Api\StudentSummary;
use App\Domain\Metrics\PathMetrics;
use App\Domain\Signal\SignalType;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON Read-API (SPEC §8; design, решение 9): доли и индексы — до сотых, даты — YYYY-MM-DD, непосчитанное — null.
 */
final class SummaryJson
{
    private const SIGNAL_PREFIX = 'diagnostics.';

    /**
     * @param array<string, mixed> $data
     */
    public static function response(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        // 0.0 в балансе остаётся 0.0, как в примере §8, а не 0
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRESERVE_ZERO_FRACTION);

        return $response->setData($data);
    }

    public static function error(string $error, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $error], $status);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(StudentSummary $summary): array
    {
        $metrics = $summary->metrics;

        return [
            'student_id' => $summary->studentId,
            'path_id' => $summary->pathId,
            'ev_minutes' => $metrics->earnedMinutes,
            'pv_minutes' => $metrics->plannedMinutes,
            'ac_minutes' => $metrics->actualMinutes,
            'spi' => self::round($metrics->spi),
            'cpi' => self::round($metrics->cpi),
            'forecast_finish' => self::date($metrics->forecastFinish),
            'planned_finish' => self::date($metrics->plannedFinish),
            'delay_days' => $metrics->delayDays,
            'week' => [
                'goal_minutes' => $summary->weekGoalMinutes,
                'logged_minutes' => $summary->weekLoggedMinutes,
                'active_minutes' => $summary->regularity->weekActiveMinutes,
                'active_days' => $summary->regularity->activeDaysInWeek,
            ],
            'streak_days' => $summary->regularity->streakDays,
            'balance' => [
                'theory' => round($summary->balance->theory, 2),
                'practice' => round($summary->balance->practice, 2),
                'interview' => round($summary->balance->interview, 2),
            ],
            'active_signals' => self::signals($summary->activeSignals),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mentorStudent(MentorStudent $student): array
    {
        return [
            'student_id' => $student->studentId,
            'path_id' => $student->pathId,
            ...self::progress($student->metrics),
            'last_activity_at' => $student->lastActivityAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'active_signals' => self::signals($student->activeSignals),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function progress(PathMetrics $metrics): array
    {
        return [
            'spi' => self::round($metrics->spi),
            'cpi' => self::round($metrics->cpi),
            'delay_days' => $metrics->delayDays,
            'forecast_finish' => self::date($metrics->forecastFinish),
        ];
    }

    /**
     * @param list<SignalType> $types
     *
     * @return list<string> без префикса diagnostics., как в примере §8
     */
    private static function signals(array $types): array
    {
        return array_map(static fn (SignalType $type): string => substr($type->value, \strlen(self::SIGNAL_PREFIX)), $types);
    }

    private static function round(?float $value): ?float
    {
        return null === $value ? null : round($value, 2);
    }

    /**
     * Даты метрик — уже полночь дня в зоне расчёта.
     */
    private static function date(?\DateTimeImmutable $day): ?string
    {
        return $day?->format('Y-m-d');
    }
}
