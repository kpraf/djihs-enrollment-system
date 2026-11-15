<?php

namespace App\Enums;

/**
 * Enrollment documents tracked by the legacy checklist.
 */
enum DocumentType: string
{
    case PsaBirthCertificate = 'psa_birth_cert';
    case LocalBirthCertificate = 'local_birth_cert';
    case ReportCard = 'report_card';
    case Form137 = 'form_137';
    case GoodMoral = 'good_moral';
    case TransferCertificate = 'transfer_cert';

    public function label(): string
    {
        return match ($this) {
            self::PsaBirthCertificate => 'PSA Birth Certificate',
            self::LocalBirthCertificate => 'Local Birth Certificate',
            self::ReportCard => 'Report Card (SF9)',
            self::Form137 => 'Form 137 (SF10)',
            self::GoodMoral => 'Good Moral Certificate',
            self::TransferCertificate => 'Transfer Certificate',
        };
    }

    /**
     * Required to be complete: a birth certificate (PSA or local) and the
     * report card; transferees also need Form 137. Same rule as the legacy system.
     *
     * @param  list<string>  $submitted  document type values marked submitted
     */
    public static function isComplete(array $submitted, LearnerType $type): bool
    {
        $hasBirthCert = in_array(self::PsaBirthCertificate->value, $submitted, true) || in_array(self::LocalBirthCertificate->value, $submitted, true);
        $hasReportCard = in_array(self::ReportCard->value, $submitted, true);
        $hasForm137 = $type !== LearnerType::Transferee || in_array(self::Form137->value, $submitted, true);

        return $hasBirthCert && $hasReportCard && $hasForm137;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $d) => ['value' => $d->value, 'label' => $d->label()], self::cases());
    }
}
