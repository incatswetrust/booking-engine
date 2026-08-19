<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Compiles docs/api-reference.md (hand-maintained, one `## Group` per
 * OpenAPI tag, one `### `METHOD /path`` per endpoint) into
 * resources/data/api-reference.php, the structured array the homepage
 * (`GET /`) renders. Kept as a separate, checked-in source file rather
 * than parsing the Markdown at request time -- the page has no reason to
 * pay a parsing cost on every hit, and the compiled array is easy to
 * diff in review.
 *
 * Re-run this after editing docs/api-reference.md:
 *   php artisan docs:generate-api-reference
 */
class GenerateApiReferenceData extends Command
{
    protected $signature = 'docs:generate-api-reference';

    protected $description = 'Compile docs/api-reference.md into resources/data/api-reference.php';

    public function handle(): int
    {
        $source = base_path('docs/api-reference.md');

        if (! file_exists($source)) {
            $this->error("Not found: {$source}");

            return self::FAILURE;
        }

        $markdown = file_get_contents($source);

        // Split off the intro (everything before the first "## ") -- it's
        // discarded here; the page's own hero copy covers it, and the
        // per-group content below is what actually gets rendered.
        $firstGroupPos = strpos($markdown, "\n## ");
        $rest = substr($markdown, $firstGroupPos + 1);

        // Split into groups on "## Heading" lines.
        $groupChunks = preg_split('/^## (.+)$/m', $rest, -1, PREG_SPLIT_DELIM_CAPTURE);
        array_shift($groupChunks); // leading empty piece before the first heading

        $groups = [];

        for ($i = 0; $i < count($groupChunks); $i += 2) {
            $groupName = trim($groupChunks[$i]);
            $groupBody = $groupChunks[$i + 1];

            // The "Global / cross-cutting" group is reference prose, not
            // real endpoints -- keep its ### subsections (Authentication
            // methods, Common error codes, etc.) as part of the group's
            // markdown intro wholesale rather than parsing them as
            // METHOD/path headings. Same for "Enums reference" at the end,
            // which has no ### subsections at all.
            if (str_starts_with($groupName, 'Global') || str_starts_with($groupName, 'Enums')) {
                $groups[] = [
                    'name' => $groupName,
                    'intro' => trim(preg_replace('/^---\s*/m', '', $groupBody)),
                    'endpoints' => [],
                ];

                continue;
            }

            $groups[] = $this->parseEndpointGroup($groupName, $groupBody);
        }

        $this->write($groups);

        $total = array_sum(array_map(fn ($g) => count($g['endpoints']), $groups));
        $this->info('Compiled '.count($groups)." groups, {$total} endpoints.");

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, intro: string, endpoints: array<int, array{method: string, path: string, idempotent: bool, slug: string, body: string}>}
     */
    private function parseEndpointGroup(string $groupName, string $groupBody): array
    {
        // Split the group body into endpoint sections on "### `METHOD /path`" headings.
        $endpointChunks = preg_split('/^### (.+)$/m', $groupBody, -1, PREG_SPLIT_DELIM_CAPTURE);
        $groupIntro = trim(preg_replace('/^---\s*/m', '', trim($endpointChunks[0])));

        $endpoints = [];

        for ($j = 1; $j < count($endpointChunks); $j += 2) {
            $heading = trim($endpointChunks[$j]);
            $body = trim(preg_replace('/---\s*$/', '', trim($endpointChunks[$j + 1] ?? '')));

            // Heading looks like: `GET /api/v1/bookings` or `POST /api/v1/bookings` — Idempotent
            if (! preg_match('/`(GET|POST|PUT|PATCH|DELETE)\s+([^`]+)`(.*)$/', $heading, $m)) {
                $this->warn("Could not parse heading, skipped: {$heading}");

                continue;
            }

            $method = $m[1];
            $path = trim($m[2]);
            $suffix = trim($m[3], " -\t");

            $slugPath = preg_replace('#^/api/v1#', '', $path);
            $slug = strtolower($method).'-'.trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($slugPath)), '-');

            $endpoints[] = [
                'method' => $method,
                'path' => $path,
                'idempotent' => stripos($suffix, 'Idempotent') !== false,
                'slug' => $slug,
                'body' => $body,
            ];
        }

        return ['name' => $groupName, 'intro' => $groupIntro, 'endpoints' => $endpoints];
    }

    /**
     * @param  array<int, array{name: string, intro: string, endpoints: array<int, array<string, mixed>>}>  $groups
     */
    private function write(array $groups): void
    {
        $export = var_export(['groups' => $groups], true);
        // var_export() emits legacy array() syntax -- normalize to [] to
        // match the rest of the codebase's style (and Pint's preference).
        $export = preg_replace('/^(\s*)array \(/m', '$1[', $export);
        $export = preg_replace('/^(\s*)\)/m', '$1]', $export);

        file_put_contents(
            resource_path('data/api-reference.php'),
            "<?php\n\nreturn {$export};\n",
        );
    }
}
