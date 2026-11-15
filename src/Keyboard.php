<?php

declare(strict_types=1);

namespace OmarKhaled\Findex;

/**
 * Converts text typed with the wrong keyboard layout.
 *
 * Supports the Arabic (101 / PC) layout against US QWERTY, in both directions.
 * Both conversions are a single strtr() call over a table compiled on first use.
 */
final class Keyboard
{
    /**
     * The unshifted Arabic (101) layout, keyed by the US QWERTY character on the same key.
     *
     * @var array<string, string>
     */
    private const array LAYOUT = [
        '`' => 'ذ',
        'q' => 'ض', 'w' => 'ص', 'e' => 'ث', 'r' => 'ق', 't' => 'ف', 'y' => 'غ',
        'u' => 'ع', 'i' => 'ه', 'o' => 'خ', 'p' => 'ح', '[' => 'ج', ']' => 'د',
        'a' => 'ش', 's' => 'س', 'd' => 'ي', 'f' => 'ب', 'g' => 'ل', 'h' => 'ا',
        'j' => 'ت', 'k' => 'ن', 'l' => 'م', ';' => 'ك', "'" => 'ط',
        'z' => 'ئ', 'x' => 'ء', 'c' => 'ؤ', 'v' => 'ر', 'b' => 'لا', 'n' => 'ى',
        'm' => 'ة', ',' => 'و', '.' => 'ز', '/' => 'ظ',
    ];

    /**
     * Shift + key combinations that type an Arabic letter.
     *
     * Every other uppercase letter maps like its lowercase key, so text typed
     * with caps lock or mobile auto-capitalization still converts.
     *
     * @var array<string, string>
     */
    private const array SHIFT_LETTERS = [
        'H' => 'أ', 'Y' => 'إ', 'N' => 'آ', 'G' => 'لأ', 'T' => 'لإ', 'B' => 'لآ',
    ];

    /**
     * Characters of the Arabic Shift layer and the key that types them.
     *
     * Letter keys are given in lowercase because search is case-insensitive.
     *
     * @var array<string, string>
     */
    private const array SHIFT_LAYER = [
        // Letters
        'أ' => 'h', 'إ' => 'y', 'آ' => 'n', 'لأ' => 'g', 'لإ' => 't', 'لآ' => 'b',
        // Tatweel and punctuation
        'ـ' => 'j', '،' => 'k', '؛' => 'p', '؟' => '?',
        // Marks: fatha, tanween fath, damma, tanween damm, kasra, tanween kasr, sukun, shadda
        "\u{064E}" => 'q', "\u{064B}" => 'w', "\u{064F}" => 'e', "\u{064C}" => 'r',
        "\u{0650}" => 'a', "\u{064D}" => 's', "\u{0652}" => 'x', "\u{0651}" => '~',
        // Symbols that sit on letter keys
        ']' => 'd', '[' => 'f', '}' => 'c', '{' => 'v', '/' => 'l', '~' => 'z',
        '‘' => 'u', '÷' => 'i', '×' => 'o', '’' => 'm',
    ];

    /**
     * The sequence typed by the B key, and also by the G key followed by the H key.
     */
    private const string LAM_ALEF = 'لا';

    /**
     * Above this many "لا" sequences only the two uniform readings are returned.
     */
    private const int MAX_AMBIGUOUS = 3;

    /** @var array<string, string>|null */
    private static ?array $toArabic = null;

    /** @var array<string, string>|null */
    private static ?array $toEnglish = null;

    /**
     * Convert text typed on a US QWERTY layout to what the same keys type in Arabic.
     *
     * Keyboard::toArabic('lpl]') === 'محمد'
     */
    public static function toArabic(string $text): string
    {
        return strtr($text, self::$toArabic ??= self::compileToArabic());
    }

    /**
     * Convert text typed on an Arabic layout to what the same keys type in English.
     *
     * "لا" is ambiguous: the B key types it, and so do G then H. Every reading
     * is returned, the "b" reading first.
     *
     * Keyboard::toEnglish('اثممخ') === ['hello']
     * Keyboard::toEnglish('لاخسف') === ['bost', 'ghost']
     *
     * @return list<string>
     */
    public static function toEnglish(string $text): array
    {
        $map = self::$toEnglish ??= self::compileToEnglish();

        $parts = explode(self::LAM_ALEF, $text);
        $ambiguous = count($parts) - 1;

        if ($ambiguous === 0) {
            return [strtr($text, $map)];
        }

        foreach ($parts as $index => $part) {
            $parts[$index] = strtr($part, $map);
        }

        if ($ambiguous > self::MAX_AMBIGUOUS) {
            return [implode('b', $parts), implode('gh', $parts)];
        }

        $readings = [];

        for ($mask = 0, $combinations = 1 << $ambiguous; $mask < $combinations; $mask++) {
            $reading = $parts[0];

            for ($index = 0; $index < $ambiguous; $index++) {
                $reading .= (($mask >> $index) & 1 ? 'gh' : 'b').$parts[$index + 1];
            }

            $readings[] = $reading;
        }

        return $readings;
    }

    /**
     * @return array<string, string>
     */
    private static function compileToArabic(): array
    {
        $map = self::SHIFT_LETTERS + self::LAYOUT;

        foreach (self::LAYOUT as $key => $arabic) {
            $map[strtoupper($key)] ??= $arabic;
        }

        return $map;
    }

    /**
     * The "لا" sequence is left out on purpose: toEnglish() resolves it itself.
     *
     * @return array<string, string>
     */
    private static function compileToEnglish(): array
    {
        $map = array_flip(self::LAYOUT) + self::SHIFT_LAYER;

        unset($map[self::LAM_ALEF]);

        return $map;
    }
}
