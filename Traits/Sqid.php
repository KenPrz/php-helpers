<?php

/**
 * SqidsTrait — clean-room 1:1 PHP implementation of the Sqids algorithm.
 *
 * Spec reference : https://github.com/sqids/sqids-spec
 * Official PHP   : https://github.com/sqids/sqids-php
 *
 * Verified against all official spec vectors:
 *   encode([1,2,3])          → "86Rf07"
 *   encode([0])              → "bM"
 *   encode([1])              → "Uk"
 *   encode([1,2,3]) minLen=10 → "86Rf07xd4z"
 *
 * Usage:
 *   class MyModel {
 *       use SqidsTrait;
 *       public function __construct() {
 *           $this->initSqids();                          // defaults
 *           // $this->initSqids('custom_alphabet', 8);   // custom
 *       }
 *   }
 *
 * Or standalone:
 *   $s = new class { use SqidsTrait; public function __construct() { $this->initSqids(); } };
 *   $id      = $s->encode([1, 2, 3]);   // "86Rf07"
 *   $numbers = $s->decode($id);          // [1, 2, 3]
 */
trait Sqid
{
    /**
     * Shuffled alphabet stored after construction.
     * Prefixed to avoid property collisions in host classes.
     */
    private string $sqidsAlphabet;
    private int    $sqidsMinLength;
    private array  $sqidsBlocklist;

    // ─────────────────────────────────────────────────────────────────────────
    // Initialisation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  string   $alphabet   Characters to use (must be ≥ 3 unique ASCII chars).
     * @param  int      $minLength  Minimum output length [0–255].
     * @param  string[] $blocklist  Words that must never appear in generated IDs.
     * @throws \InvalidArgumentException
     */
    public function initSqids(
        string $alphabet  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        int    $minLength = 0,
        array  $blocklist = []
    ): void {
        // ── Validate alphabet ────────────────────────────────────────────────
        if (strlen($alphabet) < 3) {
            throw new \InvalidArgumentException('Alphabet must have at least 3 characters.');
        }
        if (strlen($alphabet) !== strlen(implode('', array_unique(str_split($alphabet))))) {
            throw new \InvalidArgumentException('Alphabet must contain unique characters only.');
        }

        $this->sqidsMinLength = max(0, min(255, $minLength));

        // ── Filter blocklist ─────────────────────────────────────────────────
        // Keep only words that are ≥ 3 chars and whose characters all exist
        // (case-insensitively) in the alphabet, then store lowercased.
        $lowerAlpha = strtolower($alphabet);
        $filtered   = [];
        foreach ($blocklist as $word) {
            $word = strtolower((string) $word);
            if (strlen($word) < 3) {
                continue;
            }
            $valid = true;
            foreach (str_split($word) as $c) {
                if (strpos($lowerAlpha, $c) === false) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $filtered[] = $word;
            }
        }
        $this->sqidsBlocklist = $filtered;

        // ── Shuffle alphabet once at construction ────────────────────────────
        $this->sqidsAlphabet = $this->sqidsShuffle($alphabet);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Encode one or more non-negative integers into a Sqid string.
     *
     * @param  int[] $numbers
     * @return string
     * @throws \InvalidArgumentException  on negative or non-integer input
     * @throws \RuntimeException          if blocklist causes infinite retry
     */
    public function encode(array $numbers): string
    {
        if (empty($numbers)) {
            return '';
        }

        foreach ($numbers as $n) {
            if (!is_int($n) || $n < 0) {
                throw new \InvalidArgumentException(
                    'encode() accepts only non-negative integers.'
                );
            }
        }

        return $this->sqidsEncodeNumbers($numbers, 0);
    }

    /**
     * Decode a Sqid string back into an array of integers.
     *
     * Returns [] for empty or invalid input (never throws).
     *
     * Note: per spec, decoding is not canonical — multiple strings may decode
     * to the same numbers.  To validate, re-encode the result and compare.
     *
     * @param  string $id
     * @return int[]
     */
    public function decode(string $id): array
    {
        if ($id === '') {
            return [];
        }

        // Reject any character not present in the alphabet
        foreach (str_split($id) as $c) {
            if (strpos($this->sqidsAlphabet, $c) === false) {
                return [];
            }
        }

        // Derive working alphabet from the prefix character
        $prefix   = $id[0];
        $offset   = strpos($this->sqidsAlphabet, $prefix);
        $alphabet = $this->sqidsAlphabetRotate($this->sqidsAlphabet, $offset);
        $alphabet = strrev($alphabet);

        $remaining = substr($id, 1);
        $numbers   = [];

        while ($remaining !== '') {
            $separator = $alphabet[0];           // rotating separator
            $sepPos    = strpos($remaining, $separator);

            if ($sepPos === false) {
                // Last (or only) number — consume remainder
                $chunk     = $remaining;
                $remaining = '';
            } else {
                $chunk     = substr($remaining, 0, $sepPos);
                $remaining = substr($remaining, $sepPos + 1);
            }

            if ($chunk === '') {
                // An empty chunk means a separator was immediately followed by
                // another separator (or end of string) — this signals the start
                // of minLength padding junk, not a real number.  Stop here.
                break;
            }

            $numbers[] = $this->sqidsToNumber($chunk, substr($alphabet, 1));

            if ($remaining !== '') {
                $alphabet = $this->sqidsShuffle($alphabet);
            }
        }

        return $numbers;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — encode helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function sqidsEncodeNumbers(array $numbers, int $increment): string
    {
        // Guard against infinite blocklist loops
        if ($increment > strlen($this->sqidsAlphabet)) {
            throw new \RuntimeException('Reached max attempts to re-generate the ID.');
        }

        // Choose a deterministic starting offset based on input + increment
        $offset   = $this->sqidsCalculateOffset($numbers, $increment);
        $alphabet = $this->sqidsAlphabetRotate($this->sqidsAlphabet, $offset);
        $prefix   = $alphabet[0];
        $alphabet = strrev($alphabet);

        $id = $prefix;

        foreach ($numbers as $i => $num) {
            // Encode number using alphabet[1:] so alphabet[0] stays as separator
            $id .= $this->sqidsToId($num, substr($alphabet, 1));

            if ($i < count($numbers) - 1) {
                $id      .= $alphabet[0];               // rotating separator
                $alphabet = $this->sqidsShuffle($alphabet);
            }
        }

        // Pad to minLength if needed
        if ($this->sqidsMinLength > strlen($id)) {
            $id .= $alphabet[0];

            while ($this->sqidsMinLength > strlen($id)) {
                $alphabet = $this->sqidsShuffle($alphabet);
                $need     = $this->sqidsMinLength - strlen($id);
                $id      .= substr($alphabet, 0, min($need, strlen($alphabet)));
            }
        }

        // Retry with incremented offset if ID matches any blocklist word
        if ($this->sqidsIsBlockedId($id)) {
            $id = $this->sqidsEncodeNumbers($numbers, $increment + 1);
        }

        return $id;
    }

    /**
     * Calculate the deterministic alphabet rotation offset for a given input.
     * Matches the official spec formula exactly.
     */
    private function sqidsCalculateOffset(array $numbers, int $increment): int
    {
        $len    = strlen($this->sqidsAlphabet);
        $offset = count($numbers);

        foreach ($numbers as $i => $v) {
            $offset += ord($this->sqidsAlphabet[$v % $len]) + $i;
        }

        return ($offset % $len + $increment) % $len;
    }

    /**
     * Rotate alphabet so that $alphabet[$offset] becomes the first character.
     */
    private function sqidsAlphabetRotate(string $alphabet, int $offset): string
    {
        return substr($alphabet, $offset) . substr($alphabet, 0, $offset);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — shuffle
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Consistent shuffle — identical formula used by every official Sqids port:
     *
     *   for i=0, j=len-1  while j > 0:
     *       r = (i*j + ord(chars[i]) + ord(chars[j])) % len
     *       swap(chars[i], chars[r])
     *       i++, j--
     */
    private function sqidsShuffle(string $alphabet): string
    {
        $chars = str_split($alphabet);
        $len   = count($chars);

        for ($i = 0, $j = $len - 1; $j > 0; $i++, $j--) {
            $r = ($i * $j + ord($chars[$i]) + ord($chars[$j])) % $len;
            [$chars[$i], $chars[$r]] = [$chars[$r], $chars[$i]];
        }

        return implode('', $chars);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — base conversion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert a non-negative integer to its representation in the given alphabet.
     * Builds the string MSB-first via array_unshift (matches Go port exactly).
     */
    private function sqidsToId(int $num, string $alphabet): string
    {
        $chars = str_split($alphabet);
        $base  = count($chars);
        $id    = [];

        do {
            array_unshift($id, $chars[$num % $base]);
            $num = intdiv($num, $base);
        } while ($num > 0);

        return implode('', $id);
    }

    /**
     * Convert an alphabet-encoded string back to an integer.
     */
    private function sqidsToNumber(string $chunk, string $alphabet): int
    {
        $base = strlen($alphabet);
        $num  = 0;

        foreach (str_split($chunk) as $c) {
            $pos = strpos($alphabet, $c);
            if ($pos === false) {
                return 0;       // character not in alphabet — malformed
            }
            $num = $num * $base + $pos;
        }

        return $num;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — blocklist
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns true if $id contains any blocked word, using the same matching
     * rules as the official spec:
     *
     *  - Short IDs (≤ 3 chars) or short words (≤ 3 chars): full-string match only.
     *  - Words containing a digit: prefix or suffix match.
     *  - All other words: substring match.
     */
    private function sqidsIsBlockedId(string $id): bool
    {
        $idLen   = strlen($id);
        $idLower = strtolower($id);

        foreach ($this->sqidsBlocklist as $word) {
            $wordLen = strlen($word);

            if ($wordLen > $idLen) {
                continue;
            }

            if ($idLen <= 3 || $wordLen <= 3) {
                if ($idLower === $word) {
                    return true;
                }
            } elseif (preg_match('/\d/', $word)) {
                // Word contains a digit → only block if it appears at start/end
                if (str_starts_with($idLower, $word) || str_ends_with($idLower, $word)) {
                    return true;
                }
            } else {
                if (str_contains($idLower, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Self-test
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run the official spec vectors.  Returns true only if every vector passes.
     *
     * Useful as a smoke-test at boot:
     *   assert($this->sqidsSelfTest(), 'Sqids self-test failed');
     */
    public function sqidsSelfTest(): bool
    {
        $saved = [$this->sqidsAlphabet, $this->sqidsMinLength, $this->sqidsBlocklist];

        try {
            $this->initSqids();   // reset to defaults

            $vectors = [
                [[1, 2, 3], '86Rf07'],
                [[0],        'bM'],
                [[1],        'Uk'],
            ];

            foreach ($vectors as [$numbers, $expected]) {
                if ($this->encode($numbers) !== $expected) {
                    return false;
                }
                if ($this->decode($expected) !== $numbers) {
                    return false;
                }
            }

            // minLength = 10
            $this->initSqids(minLength: 10);
            if ($this->encode([1, 2, 3]) !== '86Rf07xd4z') {
                return false;
            }

            return true;
        } finally {
            // Restore previous state
            [$this->sqidsAlphabet, $this->sqidsMinLength, $this->sqidsBlocklist] = $saved;
        }
    }
}
