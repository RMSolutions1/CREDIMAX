<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Scoring interno estilo PSCPP (perfiles AA–F personas / PA–PC PyME).
 * Sin bureaux externos; se recalcula en onboarding/KYC.
 */
final class ScoringService
{
    public const BAND_LABELS = [
        'AA' => 'Sobresaliente',
        'A' => 'Excelente',
        'B' => 'Superior',
        'C' => 'Muy bueno',
        'D' => 'Bueno',
        'E' => 'Adecuado',
        'F' => 'Aceptable',
        'PA' => 'PyME Excelente',
        'PB' => 'PyME Superior',
        'PC' => 'PyME Muy bueno',
    ];

    /** TNA orientativa por banda (personas) — ajustable en admin vía settings futuros */
    public const BAND_TNA = [
        'AA' => 48.0,
        'A' => 55.0,
        'B' => 65.0,
        'C' => 78.0,
        'D' => 95.0,
        'E' => 120.0,
        'F' => 150.0,
        'PA' => 52.0,
        'PB' => 68.0,
        'PC' => 85.0,
    ];

    public function preview(int $userId): array
    {
        return $this->compute($userId, false);
    }

    public function evaluate(int $userId): array
    {
        return $this->compute($userId, true);
    }

    public static function bandLabel(string $band): string
    {
        return self::BAND_LABELS[$band] ?? $band;
    }

    public static function suggestedTna(string $band): float
    {
        return (float) (self::BAND_TNA[$band] ?? 78.0);
    }

    private function compute(int $userId, bool $persist): array
    {
        $u = App::db()->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        $docs = App::db()->fetchAll('SELECT doc_type FROM kyc_documents WHERE user_id = ?', [$userId]);
        $types = array_column($docs, 'doc_type');
        $isPyme = (($u['account_type'] ?? 'persona') === 'pyme');
        $score = 40;

        if (!empty($u['dni']) || !empty($u['cuit'])) {
            $score += 8;
        }
        if (!empty($u['phone_verified_at'])) {
            $score += 8;
        }
        if (!empty($u['email_verified_at'])) {
            $score += 6;
        }
        if (!empty($u['address_street']) && !empty($u['address_city'])) {
            $score += 6;
        }
        if ((float) ($u['monthly_income'] ?? 0) >= 400000) {
            $score += 14;
        } elseif ((float) ($u['monthly_income'] ?? 0) >= 200000) {
            $score += 10;
        } elseif ((float) ($u['monthly_income'] ?? 0) >= 100000) {
            $score += 6;
        }
        if ((int) ($u['job_seniority_months'] ?? 0) >= 24) {
            $score += 10;
        } elseif ((int) ($u['job_seniority_months'] ?? 0) >= 12) {
            $score += 8;
        } elseif ((int) ($u['job_seniority_months'] ?? 0) >= 6) {
            $score += 5;
        } elseif ((int) ($u['job_seniority_months'] ?? 0) >= 3) {
            $score += 3;
        }
        if (in_array('dni_front', $types, true) && in_array('dni_back', $types, true)) {
            $score += 8;
        }
        if (in_array('selfie', $types, true)) {
            $score += 6;
        }
        if (in_array('proof_address', $types, true)) {
            $score += 4;
        }
        if (in_array('other', $types, true)) {
            $score += 4;
        }
        if (!empty($u['terms_accepted_at']) && !empty($u['privacy_accepted_at'])) {
            $score += 4;
        }
        if (!empty($u['is_pep'])) {
            $score -= 10;
        }

        $score = max(0, min(100, $score));

        if ($isPyme) {
            $band = match (true) {
                $score >= 80 => 'PA',
                $score >= 60 => 'PB',
                default => 'PC',
            };
            $maxSuggested = match ($band) {
                'PA' => 5000000,
                'PB' => 2500000,
                default => 1000000,
            };
        } else {
            $band = match (true) {
                $score >= 92 => 'AA',
                $score >= 84 => 'A',
                $score >= 72 => 'B',
                $score >= 60 => 'C',
                $score >= 48 => 'D',
                $score >= 36 => 'E',
                default => 'F',
            };
            $maxSuggested = match ($band) {
                'AA' => 5000000,
                'A' => 3000000,
                'B' => 1500000,
                'C' => 800000,
                'D' => 400000,
                'E' => 200000,
                default => 80000,
            };
        }

        $result = [
            'score' => $score,
            'band' => $band,
            'band_label' => self::bandLabel($band),
            'suggested_tna' => self::suggestedTna($band),
            'max_suggested' => $maxSuggested,
            'account_type' => $isPyme ? 'pyme' : 'persona',
            'factors' => [
                'identity_docs' => in_array('dni_front', $types, true),
                'selfie' => in_array('selfie', $types, true),
                'income' => (float) ($u['monthly_income'] ?? 0),
                'seniority' => (int) ($u['job_seniority_months'] ?? 0),
                'contact_verified' => !empty($u['email_verified_at']) || !empty($u['phone_verified_at']),
            ],
        ];

        if ($persist) {
            App::db()->update('users', [
                'risk_score' => $score,
                'risk_band' => $band,
            ], 'id = ?', [$userId]);
        }
        return $result;
    }
}
