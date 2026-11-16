<?php

namespace App\Services\Import;

/**
 * What to type in each column of the encoding template, and what not to.
 *
 * One list, used three times so they never disagree: the HOW TO FILL IN
 * sheet and the per-column cell tips in the Excel template, and the guide
 * on the Bulk Import page (which also covers the CSV template). It
 * describes what EncodingSheetImporter actually does with each value.
 */
class TemplateGuide
{
    /** The learner can't be imported without this; a wrong value skips the row. */
    public const REQUIRED = 'required';

    /** Fill it in if you have it; a blank or odd value only adds a note. */
    public const OPTIONAL = 'optional';

    /** Required for Grades 11 and 12; left blank for Grades 7 to 10. */
    public const SENIOR_HIGH = 'senior_high';

    /** Filled in by the template; leave it alone. */
    public const AUTOMATIC = 'automatic';

    /**
     * @return list<array{column: string, header: string, need: string, enter: string, example: string, avoid: string, tip: string}>
     */
    public static function columns(): array
    {
        return [
            self::column('A', 'NO.', self::OPTIONAL,
                'A running number for your own counting. The system does not read this column.', '1',
                '',
                'Optional. Your own row number; not imported.'),
            self::column('B', 'GRADE LEVEL TO ENROLL', self::REQUIRED,
                'The grade the learner is enrolling in, from GRADE 7 to GRADE 12.', 'GRADE 11',
                "Don't type the grade they just finished. Don't write words like \"eleven\". A row without a grade from 7 to 12 is not imported.",
                'Required. GRADE 7 to GRADE 12: the grade they are enrolling in.'),
            self::column('C', 'FULL NAME', self::AUTOMATIC,
                'Nothing. It fills in from the last, first and middle name, so the list is easy to read and sort, as in the school\'s workbook.', 'DELA CRUZ, JUAN SANTOS',
                "Don't type over it. The system reads the three name columns, not this one.",
                'Fills in by itself from columns D to F.'),
            self::column('D', "LEARNER'S NAME: LAST NAME", self::REQUIRED,
                'The surname, with any name extension such as JR. or III.', 'DELA CRUZ JR.',
                "Don't put the whole name in one cell. A row without a last name is not imported.",
                'Required. Surname, plus JR./III if any.'),
            self::column('E', "LEARNER'S NAME: FIRST NAME", self::REQUIRED,
                'The given name or names.', 'JUAN',
                "Don't leave it blank. A row without a first name is not imported.",
                'Required. Given name(s).'),
            self::column('F', "LEARNER'S NAME: MIDDLE NAME", self::OPTIONAL,
                "The mother's maiden surname. Leave it blank if there is none.", 'SANTOS',
                "Don't type only the middle initial if you know the full middle name.",
                'Optional. Full middle name, not just the initial.'),
            self::column('G', 'TYPE OF LEARNER', self::REQUIRED,
                'Pick from the list: OLD STUDENT, NEW STUDENT, ALS, BALIK-ARAL, REPEATER or TRANSFEREE, starting with REGULAR or IRREGULAR.',
                'REGULAR - OLD STUDENT (DJIHS)',
                "Don't leave it blank or write your own wording. A row without one of these words is not imported.",
                'Required. Pick from the list. IRREGULAR marks the learner as irregular.'),
            self::column('H', 'TRACK', self::OPTIONAL,
                'ACADEMIC or TVL, for Grades 11 and 12 only.', 'ACADEMIC',
                "Leave it blank for Grades 7 to 10. If it doesn't match the strand, the strand's track is used.",
                'Grades 11-12 only. Blank for Grades 7-10.'),
            self::column('I', 'STRAND', self::SENIOR_HIGH,
                'For Grades 11 and 12, pick the strand from the list. Leave it blank for Grades 7 to 10.',
                'HUMANITIES AND SOCIAL SCIENCES (HUMSS)',
                "Don't type a strand the school doesn't offer. A Grade 11 or 12 row without a known strand is not imported. A strand on a Grade 7 to 10 row is ignored.",
                'Grades 11-12: required, pick from the list. Grades 7-10: leave blank.'),
            self::column('J', 'LRN', self::REQUIRED,
                'The 12-digit Learner Reference Number. The Excel template keeps this column as text, so every digit stays. In a CSV opened in Excel, type an apostrophe first.',
                '123456789012',
                "Don't let Excel turn it into 1.23457E+11; those digits are lost for good. Don't add spaces or dashes. A learner without an LRN is still saved, with a note.",
                'Required. The 12-digit LRN, digits only. This column is text, so leading zeros stay.'),
            self::column('K', 'BIRTH DATE (mm/dd/yyyy)', self::OPTIONAL,
                'Month, day, then year.', '03/15/2008',
                "Don't write the day first (15/03/2008) or only the year. A blank birth date adds a note.",
                'mm/dd/yyyy, for example 03/15/2008. Month first.'),
            self::column('L', 'AGE', self::AUTOMATIC,
                'Nothing. It works itself out from the birth date.', '17',
                "Don't type over it. The system reads the birth date, not this column.",
                'Fills in by itself from the birth date.'),
            self::column('M', 'SEX', self::REQUIRED,
                'MALE or FEMALE (M or F also works).', 'FEMALE',
                "Don't leave it blank. A row without MALE or FEMALE is not imported.",
                'Required. MALE or FEMALE.'),
            self::column('N', 'FOR SF8: WEIGHT (kg)', self::OPTIONAL,
                'Weight in kilograms, as a number only.', '52',
                "Don't add \"kg\" or other words.",
                'Kilograms, number only. Example: 52'),
            self::column('O', 'FOR SF8: HEIGHT (mtrs)', self::OPTIONAL,
                'Height in metres, as a number only.', '1.62',
                "Don't type centimetres (162). They are converted with a note, but metres are expected. Other numbers are left blank.",
                'Metres, number only. Example: 1.62 (not 162).'),
            self::column('P', 'OTHER DETAILS: INDIGENOUS PEOPLES (IP)', self::OPTIONAL,
                'YES or NO.', 'NO',
                'Blank counts as NO.',
                'YES or NO. Blank counts as NO.'),
            self::column('Q', 'OTHER DETAILS: IF YES, PLEASE SPECIFY', self::OPTIONAL,
                'The indigenous group, only if column P is YES.', 'AETA',
                "Don't fill this in when column P is NO.",
                'Only if IP is YES: the group name.'),
            self::column('R', "OTHER DETAILS: 4P'S BENEFICIARY", self::OPTIONAL,
                'YES or NO.', 'NO',
                'Blank counts as NO.',
                'YES or NO. Blank counts as NO.'),
            self::column('S', 'CURRENT ADDRESS: HOUSE NO.', self::OPTIONAL,
                'House or lot number.', '12', '', 'House or lot number.'),
            self::column('T', 'CURRENT ADDRESS: STREET NAME', self::OPTIONAL,
                'Street, subdivision or purok.', 'RIZAL ST.', '', 'Street, subdivision or purok.'),
            self::column('U', 'CURRENT ADDRESS: BARANGAY', self::OPTIONAL,
                'Barangay name.', 'DON JOSE', '', 'Barangay name.'),
            self::column('V', 'CURRENT ADDRESS: MUNICIPALITY / CITY', self::OPTIONAL,
                'Municipality or city.', 'SANTA ROSA',
                "Don't put the province here; that adds a note.",
                'Municipality or city, not the province.'),
            self::column('W', 'CURRENT ADDRESS: PROVINCE', self::OPTIONAL,
                'Province.', 'LAGUNA', '', 'Province.'),
            self::column('X', 'CURRENT ADDRESS: COUNTRY', self::OPTIONAL,
                'Country.', 'PHILIPPINES', '', 'Country.'),
            self::column('Y', 'CURRENT ADDRESS: ZIP CODE', self::OPTIONAL,
                'The 4-digit ZIP code.', '4026', '', '4 digits.'),
            self::column('Z', "LEGAL GUARDIAN'S NAME: LAST NAME", self::OPTIONAL,
                "Guardian's surname.", 'DELA CRUZ', '', "Guardian's surname."),
            self::column('AA', "LEGAL GUARDIAN'S NAME: FIRST NAME", self::OPTIONAL,
                "Guardian's given name.", 'MARIA', '', "Guardian's given name."),
            self::column('AB', "LEGAL GUARDIAN'S NAME: MIDDLE NAME", self::OPTIONAL,
                "Guardian's middle name.", 'REYES', '', "Guardian's middle name."),
            self::column('AC', "LEGAL GUARDIAN'S NAME: CONTACT NO.", self::OPTIONAL,
                'An 11-digit mobile number starting with 09. The Excel template keeps this column as text, so the 0 stays.', '09171234567',
                "Don't type a landline or a number with missing digits; that adds a note. +639... and 9... are fixed to 09... for you.",
                '11-digit mobile number: 09XXXXXXXXX. This column is text, so the 0 stays.'),
            self::column('AD', 'DATE ENCODED (mm/dd/yyyy)', self::OPTIONAL,
                'The day the learner was encoded.', '07/03/2026',
                "Don't leave it blank if you can help it; that adds a note.",
                'mm/dd/yyyy: the day this row was encoded.'),
            self::column('AE', 'ENROLLMENTSTATUS', self::REQUIRED,
                'ENROLLED, or CANCELLED for a learner whose enrollment was cancelled. See the enrollment status guide.', 'ENROLLED',
                "Don't type PENDING, DROPPED or TRANSFERRED OUT: anything other than CANCELLED is imported as ENROLLED.",
                'ENROLLED or CANCELLED only. Anything else, even blank, is imported as ENROLLED.'),
            self::column('AF', 'REMARKS', self::OPTIONAL,
                'Short notes for the registrar.', 'Shifted from HUMSS to ABM',
                "Don't use remarks to set a status. Write CANCELLED in ENROLLMENTSTATUS instead.",
                'Short notes. Not used to set a status.'),
            self::column('AG', "FATHER'S NAME: LAST NAME", self::OPTIONAL,
                "Father's surname. Shown on SF1.", 'DELA CRUZ',
                "Don't put the whole name in one cell. Leave the three father columns blank if unknown.",
                "Father's surname. Shown on SF1."),
            self::column('AH', "FATHER'S NAME: FIRST NAME", self::OPTIONAL,
                "Father's given name.", 'PEDRO', '', "Father's given name."),
            self::column('AI', "FATHER'S NAME: MIDDLE NAME", self::OPTIONAL,
                "Father's middle name.", 'GARCIA', '', "Father's middle name."),
            self::column('AJ', "MOTHER'S MAIDEN NAME: LAST NAME", self::OPTIONAL,
                "Mother's surname before marriage. Shown on SF1.", 'SANTOS',
                "Don't type her married surname; SF1 asks for her maiden name.",
                "Mother's MAIDEN surname (before marriage). Shown on SF1."),
            self::column('AK', "MOTHER'S MAIDEN NAME: FIRST NAME", self::OPTIONAL,
                "Mother's given name.", 'ROSA', '', "Mother's given name."),
            self::column('AL', "MOTHER'S MAIDEN NAME: MIDDLE NAME", self::OPTIONAL,
                "Mother's middle name.", 'LOPEZ', '', "Mother's middle name."),
            self::column('AM', "LEGAL GUARDIAN'S RELATIONSHIP TO LEARNER", self::OPTIONAL,
                'How the guardian in columns Z to AB is related to the learner. Shown on SF1.', 'GRANDMOTHER',
                "Don't leave it blank when the guardian is not a parent.",
                'How the guardian is related: MOTHER, FATHER, GRANDMOTHER, AUNT...'),
        ];
    }

    /**
     * What to put in ENROLLMENTSTATUS, and what to do in the app instead.
     *
     * @return array{do: list<string>, dont: list<string>}
     */
    public static function status(): array
    {
        return [
            'do' => [
                'Type ENROLLED for every learner who is enrolling. Imported learners are enrolled right away, with no review step.',
                'Type CANCELLED only for a learner whose enrollment was cancelled before classes. They are kept on record but not counted.',
                'Pick from the dropdown in the Excel template so the spelling is exact. In a CSV, type the word in capital letters.',
            ],
            'dont' => [
                "Don't type PENDING to send a learner for review. Encode them on New Enrollment instead.",
                "Don't type DROPPED or TRANSFERRED OUT. They would be imported as ENROLLED. Change the learner's status on their record in the app.",
                "Don't leave it blank for a cancelled learner. A blank status is imported as ENROLLED.",
                "Don't re-import an old copy of the sheet after changing statuses in the app. The sheet's status replaces the one in the app.",
            ],
        ];
    }

    /**
     * Rules for the whole sheet, not one column.
     *
     * @return array{do: list<string>, dont: list<string>}
     */
    public static function general(): array
    {
        return [
            'do' => [
                'Start typing learners on row 3. Rows 1 and 2 are the headers.',
                "Columns A to AF are laid out like the school's ENROLLMENT_ENCODING workbook, so rows copied from it paste straight in. Parents' names and the guardian's relationship (AG to AM) were added for SF1.",
                'Use one row per learner.',
                'Use the dropdowns in the Excel template. In a CSV, type values exactly as the dropdowns show them.',
                'In a CSV opened in Excel, type the LRN and contact number with an apostrophe first (\'123456789012) so Excel keeps every digit. The Excel template does this for you.',
                'Save as .xlsx, or as .csv (CSV UTF-8) if you used the CSV template.',
                'Upload the file on the Bulk Import page and check it first. Nothing is saved until you press Import.',
                'To correct a learner, fix the row and import the file again. Learners are updated, not added twice.',
            ],
            'dont' => [
                "Don't rename, move or delete the header rows or columns. The system finds each column by its header.",
                "Don't rename the MAIN_ENCODING_SHEET tab in the Excel template.",
                "Don't merge cells, add blank rows between learners, or put totals at the bottom.",
                "Don't type over FULL NAME (C) or AGE (L). They fill in by themselves.",
                "Don't type a strand or track for Grades 7 to 10.",
                "Don't open and re-save a CSV in Excel without checking the LRN column. Excel can shorten LRNs to 1.23457E+11.",
            ],
        ];
    }

    /**
     * @return array{column: string, header: string, need: string, enter: string, example: string, avoid: string, tip: string}
     */
    private static function column(string $column, string $header, string $need, string $enter, string $example, string $avoid, string $tip): array
    {
        return compact('column', 'header', 'need', 'enter', 'example', 'avoid', 'tip');
    }

    /**
     * Everything the Bulk Import page shows.
     *
     * @return array{columns: list<array<string, string>>, status: array{do: list<string>, dont: list<string>}, general: array{do: list<string>, dont: list<string>}}
     */
    public static function toArray(): array
    {
        return ['columns' => self::columns(), 'status' => self::status(), 'general' => self::general()];
    }
}
