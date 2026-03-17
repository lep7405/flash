<?php

namespace App\Http\Controllers;

use App\Models\Flash;
use App\Models\FlashExample;
use App\Models\FlashGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class FlashController extends Controller
{
    private const SPREADSHEET_MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const IMPORT_HEADERS = [
        'vocabulary',
        'pinyin',
        'group_id',
        'example_sentence',
        'example_pinyin',
        'example_translation_vi',
    ];

    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 12), 1), 50);
        $groupId = $this->nullableTrim($request->query('group_id'));
        $ungroupedOnly = $request->boolean('ungrouped');

        $flashesQuery = Flash::query()
            ->with('examples')
            ->latest();

        if ($ungroupedOnly) {
            $flashesQuery->whereNull('group_id');
        } elseif ($groupId !== null) {
            $flashesQuery->where('group_id', $groupId);
        }

        $flashes = $flashesQuery
            ->limit($limit)
            ->get()
            ->map(fn (Flash $flash) => $this->flashPayload($flash))
            ->all();

        $groups = Flash::query()
            ->select('group_id', DB::raw('COUNT(*) as flash_count'))
            ->whereNotNull('group_id')
            ->groupBy('group_id')
            ->orderBy('group_id')
            ->get()
            ->map(fn (Flash $flash) => [
                'id' => $flash->group_id,
                'flash_count' => (int) ($flash->flash_count ?? 0),
            ])
            ->all();

        $ungroupedCount = Flash::query()
            ->whereNull('group_id')
            ->count();

        return response()->json([
            'data' => $flashes,
            'groups' => $groups,
            'ungrouped_count' => $ungroupedCount,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateFlashPayload($request);

        ['flash' => $flash, 'group' => $group] = $this->persistFlash(data: $data);

        return response()->json([
            'message' => 'Flash created successfully.',
            'flash' => $this->flashPayload($flash),
            'group' => $group ? ['id' => $group->id] : null,
        ], 201);
    }

    public function update(Request $request, Flash $flash): JsonResponse
    {
        $data = $this->validateFlashPayload($request);

        ['flash' => $updatedFlash, 'group' => $group] = $this->persistFlash(
            flash: $flash,
            data: $data,
        );

        return response()->json([
            'message' => 'Flash updated successfully.',
            'flash' => $this->flashPayload($updatedFlash),
            'group' => $group ? ['id' => $group->id] : null,
        ]);
    }

    public function destroy(Flash $flash): JsonResponse
    {
        $flash->delete();

        return response()->json([
            'message' => 'Flash deleted successfully.',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            return response()->json([
                'message' => 'Only .csv and .xlsx files are supported.',
            ], 422);
        }

        $createdCount = 0;
        $mergedCount = 0;
        $exampleCreatedCount = 0;
        $skippedCount = 0;

        try {
            $groupedFlashes = $this->buildGroupedImportFlashes($file, $extension, $skippedCount);
        } catch (RuntimeException $exception) {
            $payload = [
                'message' => $exception->getMessage(),
            ];

            if ($exception->getMessage() === 'Invalid header. Please use the required template columns in exact order.') {
                $payload['required_header'] = self::IMPORT_HEADERS;
            }

            return response()->json($payload, 422);
        }

        $this->runWriteOperation(function () use ($groupedFlashes, &$createdCount, &$mergedCount, &$exampleCreatedCount): void {
            $existingFlashes = $this->loadExistingFlashesByImportKey(array_keys($groupedFlashes));

            foreach ($groupedFlashes as $importKey => $group) {
                $flash = $existingFlashes[$importKey] ?? null;

                if ($group['group_id'] !== null) {
                    FlashGroup::query()->firstOrCreate(['id' => $group['group_id']]);
                }

                if (! $flash) {
                    $flash = Flash::query()->create([
                        'vocabulary' => $group['vocabulary'],
                        'pinyin' => $group['pinyin'],
                        'group_id' => $group['group_id'],
                    ]);

                    $flash->setRelation('examples', collect());
                    $existingFlashes[$importKey] = $flash;
                    $createdCount++;
                } else {
                    $mergedCount++;
                    $updates = [];

                    if (($flash->pinyin === null || trim((string) $flash->pinyin) === '') && $group['pinyin'] !== null) {
                        $updates['pinyin'] = $group['pinyin'];
                    }

                    if ($updates !== []) {
                        $flash->update($updates);
                        $flash->forceFill($updates);
                    }

                    $flash->loadMissing('examples');
                }

                $exampleCreatedCount += $this->appendGroupedExamplesToFlash($flash, $group['examples']);
            }
        });

        return response()->json([
            'message' => 'Import completed successfully.',
            'created' => $createdCount,
            'merged' => $mergedCount,
            'examples_created' => $exampleCreatedCount,
            'skipped' => $skippedCount,
        ], 201);
    }

    private function validateFlashPayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'vocabulary' => ['required', 'string', 'max:255'],
            'pinyin' => ['nullable', 'string', 'max:255'],
            'examples' => ['nullable', 'array'],
            'examples.*.sentence' => ['nullable', 'string', 'max:2000'],
            'examples.*.pinyin' => ['nullable', 'string', 'max:2000'],
            'examples.*.translation_vi' => ['nullable', 'string', 'max:2000'],
            'group_mode' => ['required', Rule::in(['solo', 'new', 'existing'])],
            'group_id' => ['nullable', 'string', 'max:64', 'required_if:group_mode,existing'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (trim($request->string('vocabulary')->toString()) === '') {
                $validator->errors()->add('vocabulary', 'Vocabulary is required.');
            }

            if ($request->string('group_mode')->toString() === 'existing') {
                $groupId = trim($request->string('group_id')->toString());

                if ($groupId !== '' && ! FlashGroup::query()->whereKey($groupId)->exists()) {
                    $validator->errors()->add('group_id', 'The selected group does not exist.');
                }
            }

            foreach ($request->input('examples', []) as $index => $example) {
                $sentence = trim((string) ($example['sentence'] ?? ''));
                $pinyin = trim((string) ($example['pinyin'] ?? ''));
                $translation = trim((string) ($example['translation_vi'] ?? ''));

                if ($sentence !== '' || $pinyin !== '' || $translation !== '') {
                    if ($sentence === '') {
                        $validator->errors()->add(
                            "examples.$index.sentence",
                            'Example sentence is required when example data is provided.',
                        );
                    }
                }
            }
        });

        $data = $validator->validate();
        $data['examples'] = $this->normalizeExamples($data['examples'] ?? []);

        return $data;
    }

    private function persistFlash(?Flash $flash = null, array $data = []): array
    {
        return $this->runWriteOperation(function () use ($flash, $data): array {
            $group = null;
            $groupId = null;

            if ($data['group_mode'] === 'new') {
                $groupId = $this->generateGroupId();
                $group = FlashGroup::query()->create([
                    'id' => $groupId,
                ]);
            }

            if ($data['group_mode'] === 'existing') {
                $group = FlashGroup::query()->findOrFail(trim($data['group_id']));
                $groupId = $group->id;
            }

            if ($flash) {
                $flash->update([
                    'vocabulary' => trim($data['vocabulary']),
                    'pinyin' => $this->nullableTrim($data['pinyin'] ?? null),
                    'group_id' => $groupId,
                ]);

                $this->syncExamples($flash, $data['examples'] ?? []);

                return [
                    'flash' => $flash->fresh()->load('examples'),
                    'group' => $group,
                ];
            }

            $flash = Flash::query()->create([
                'vocabulary' => trim($data['vocabulary']),
                'pinyin' => $this->nullableTrim($data['pinyin'] ?? null),
                'group_id' => $groupId,
            ]);

            $this->syncExamples($flash, $data['examples'] ?? []);

            return [
                'flash' => $flash->load('examples'),
                'group' => $group,
            ];
        });
    }

    private function runWriteOperation(callable $callback): mixed
    {
        if (DB::getDriverName() === 'sqlite') {
            return $callback();
        }

        return DB::transaction($callback);
    }

    private function flashPayload(Flash $flash): array
    {
        $flash->loadMissing('examples');

        return [
            'id' => $flash->id,
            'vocabulary' => $flash->vocabulary,
            'pinyin' => $flash->pinyin,
            'examples' => $flash->examples->map(fn (FlashExample $example) => [
                'id' => $example->id,
                'sentence' => $example->sentence,
                'pinyin' => $example->pinyin,
                'translation_vi' => $example->translation_vi,
            ])->all(),
            'group_id' => $flash->group_id,
            'created_at' => $flash->created_at?->toISOString(),
        ];
    }

    private function generateGroupId(): string
    {
        do {
            $groupId = (string) Str::uuid();
        } while (FlashGroup::query()->whereKey($groupId)->exists());

        return $groupId;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeExamples(array $examples): array
    {
        return collect($examples)
            ->map(function ($example): array {
                return [
                    'sentence' => $this->nullableTrim($example['sentence'] ?? null),
                    'pinyin' => $this->nullableTrim($example['pinyin'] ?? null),
                    'translation_vi' => $this->nullableTrim($example['translation_vi'] ?? null),
                ];
            })
            ->filter(fn (array $example): bool => (bool) $example['sentence'])
            ->values()
            ->all();
    }

    private function syncExamples(Flash $flash, array $examples): void
    {
        $flash->examples()->delete();

        if ($examples === []) {
            return;
        }

        $flash->examples()->createMany($examples);
    }

    private function normalizeHeader(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function normalizeVocabularyKey(string $vocabulary): string
    {
        $value = trim($vocabulary);

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if ($normalized !== false) {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    private function buildGroupedImportFlashes(UploadedFile $file, string $extension, int &$skippedCount): array
    {
        $groupedFlashes = [];
        $sawHeader = false;

        $this->processImportRows(
            $file,
            $extension,
            function (array $header) use (&$sawHeader): void {
                $sawHeader = true;

                if (array_map(fn ($value) => $this->normalizeHeader($value), $header) !== self::IMPORT_HEADERS) {
                    throw new RuntimeException(
                        'Invalid header. Please use the required template columns in exact order.',
                    );
                }
            },
            function (array $cells, int $line) use (&$groupedFlashes, &$skippedCount): void {
                $normalized = array_pad($cells, count(self::IMPORT_HEADERS), null);

                $vocabulary = $this->nullableTrim((string) $normalized[0]);
                $pinyin = $this->nullableTrim((string) $normalized[1]);
                $groupId = $this->nullableTrim((string) $normalized[2]);
                $exampleSentence = $this->nullableTrim((string) $normalized[3]);
                $examplePinyin = $this->nullableTrim((string) $normalized[4]);
                $exampleTranslation = $this->nullableTrim((string) $normalized[5]);

                $isEmptyRow = $vocabulary === null
                    && $pinyin === null
                    && $groupId === null
                    && $exampleSentence === null
                    && $examplePinyin === null
                    && $exampleTranslation === null;

                if ($isEmptyRow) {
                    $skippedCount++;
                    return;
                }

                if ($vocabulary === null) {
                    throw new RuntimeException("Row {$line}: vocabulary is required.");
                }

                if ($exampleSentence === null && ($examplePinyin !== null || $exampleTranslation !== null)) {
                    throw new RuntimeException(
                        "Row {$line}: example_sentence is required when example columns are filled.",
                    );
                }

                $importKey = $this->buildImportFlashKey($vocabulary, $groupId);

                if (! isset($groupedFlashes[$importKey])) {
                    $groupedFlashes[$importKey] = [
                        'vocabulary' => $vocabulary,
                        'group_id' => $groupId,
                        'pinyin' => null,
                        'examples' => [],
                    ];
                }

                if ($groupedFlashes[$importKey]['pinyin'] === null && $pinyin !== null) {
                    $groupedFlashes[$importKey]['pinyin'] = $pinyin;
                }

                if ($exampleSentence !== null) {
                    $groupedFlashes[$importKey]['examples'][] = [
                        'sentence' => $exampleSentence,
                        'pinyin' => $examplePinyin,
                        'translation_vi' => $exampleTranslation,
                    ];
                }
            },
        );

        if (! $sawHeader) {
            throw new RuntimeException('Import file is empty.');
        }

        return $groupedFlashes;
    }

    private function buildImportFlashKey(string $vocabulary, ?string $groupId): string
    {
        return json_encode([
            $this->normalizeVocabularyKey($vocabulary),
            $groupId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function loadExistingFlashesByImportKey(array $importKeys): array
    {
        if ($importKeys === []) {
            return [];
        }

        $importKeyLookup = array_fill_keys($importKeys, true);
        $matchedFlashIdsByKey = [];

        foreach (Flash::query()->select(['id', 'vocabulary', 'group_id'])->lazyById() as $flash) {
            $importKey = $this->buildImportFlashKey($flash->vocabulary, $flash->group_id);

            if (! isset($importKeyLookup[$importKey])) {
                continue;
            }

            $matchedFlashIdsByKey[$importKey] = $flash->id;
        }

        if ($matchedFlashIdsByKey === []) {
            return [];
        }

        $flashesById = Flash::query()
            ->with('examples')
            ->whereKey(array_values($matchedFlashIdsByKey))
            ->get()
            ->keyBy('id');

        $flashes = [];

        foreach ($matchedFlashIdsByKey as $importKey => $flashId) {
            $flash = $flashesById->get($flashId);

            if ($flash === null) {
                continue;
            }

            $flashes[$importKey] = $flash;
        }

        return $flashes;
    }

    private function appendGroupedExamplesToFlash(Flash $flash, array $examples): int
    {
        $existingKeys = [];

        $flash->loadMissing('examples');

        foreach ($flash->examples as $example) {
            $existingKeys[$this->buildImportExampleKey([
                'sentence' => $example->sentence,
                'pinyin' => $example->pinyin,
                'translation_vi' => $example->translation_vi,
            ])] = true;
        }

        $toCreate = [];

        foreach ($this->deduplicateImportExamples($examples) as $example) {
            $key = $this->buildImportExampleKey($example);

            if (isset($existingKeys[$key])) {
                continue;
            }

            $existingKeys[$key] = true;
            $toCreate[] = $example;
        }

        if ($toCreate !== []) {
            $flash->examples()->createMany($toCreate);
        }

        return count($toCreate);
    }

    private function deduplicateImportExamples(array $examples): array
    {
        $unique = [];
        $result = [];

        foreach ($examples as $example) {
            $normalized = [
                'sentence' => $example['sentence'],
                'pinyin' => $example['pinyin'],
                'translation_vi' => $example['translation_vi'],
            ];

            $key = $this->buildImportExampleKey($normalized);

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    private function buildImportExampleKey(array $example): string
    {
        return implode('|', [
            $this->nullableTrim($example['sentence'] ?? null) ?? '',
            $this->nullableTrim($example['pinyin'] ?? null) ?? '',
            $this->nullableTrim($example['translation_vi'] ?? null) ?? '',
        ]);
    }

    private function processImportRows(
        UploadedFile $file,
        string $extension,
        callable $onHeader,
        callable $onRow,
    ): void {
        if ($extension === 'csv') {
            $this->processCsvRows($file, $onHeader, $onRow);

            return;
        }

        $this->processXlsxRows($file, $onHeader, $onRow);
    }

    private function processCsvRows(UploadedFile $file, callable $onHeader, callable $onRow): void
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Cannot read uploaded csv file.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open uploaded csv file.');
        }

        $line = 0;
        $processedHeader = false;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (! $processedHeader) {
                $onHeader($row);
                $processedHeader = true;
                continue;
            }

            $onRow($row, $line);
        }

        fclose($handle);
    }

    private function processXlsxRows(UploadedFile $file, callable $onHeader, callable $onRow): void
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Cannot read uploaded xlsx file.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new RuntimeException('Cannot open uploaded xlsx file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $zip->close();

        $sheetStream = sprintf('zip://%s#%s', $path, 'xl/worksheets/sheet1.xml');

        if (! $this->zipEntryExists($path, 'xl/worksheets/sheet1.xml')) {
            throw new RuntimeException('Uploaded xlsx has no sheet1.');
        }

        $reader = new \XMLReader();

        if (! $reader->open($sheetStream, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Uploaded xlsx is invalid.');
        }

        $line = 0;
        $processedHeader = false;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXml();

                if ($rowXml === '') {
                    continue;
                }

                $rowNode = simplexml_load_string($rowXml);

                if ($rowNode === false) {
                    throw new RuntimeException('Uploaded xlsx is invalid.');
                }

                $row = [];

                foreach ($this->spreadsheetXPath($rowNode, './s:c') as $cell) {
                    $reference = (string) $cell['r'];
                    $columnIndex = $this->columnIndexFromReference($reference);
                    $row[$columnIndex] = $this->extractXlsxCellValue($cell, $sharedStrings);
                }

                if ($row === []) {
                    continue;
                }

                $line++;
                $maxColumnIndex = max(array_keys($row));
                $normalized = array_fill(0, $maxColumnIndex + 1, '');

                foreach ($row as $index => $value) {
                    $normalized[$index] = $value;
                }

                if (! $processedHeader) {
                    $onHeader($normalized);
                    $processedHeader = true;
                    continue;
                }

                $onRow($normalized, $line);
            }
        } finally {
            $reader->close();
        }
    }

    private function zipEntryExists(string $path, string $entry): bool
    {
        $stream = @fopen(sprintf('zip://%s#%s', $path, $entry), 'rb');

        if ($stream === false) {
            return false;
        }

        fclose($stream);

        return true;
    }

    private function readSharedStrings(\ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');

        if ($content === false) {
            return [];
        }

        $xml = simplexml_load_string($content);

        if ($xml === false) {
            return [];
        }

        $strings = [];

        foreach ($this->spreadsheetXPath($xml, '/s:sst/s:si') as $item) {
            $textNodes = $this->spreadsheetXPath($item, './s:t');

            if ($textNodes !== []) {
                $strings[] = implode('', array_map('strval', $textNodes));
                continue;
            }

            $runTextNodes = $this->spreadsheetXPath($item, './s:r/s:t');
            $strings[] = implode('', array_map('strval', $runTextNodes));
        }

        return $strings;
    }

    private function columnIndexFromReference(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?: 'A';
        $index = 0;

        foreach (str_split($letters) as $char) {
            $index = ($index * 26) + (ord($char) - ord('A') + 1);
        }

        return max(0, $index - 1);
    }

    private function extractXlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        $valueNodes = $this->spreadsheetXPath($cell, './s:v');
        $raw = $valueNodes === [] ? '' : (string) $valueNodes[0];

        if ($type === 's') {
            $sharedIndex = (int) $raw;

            return (string) ($sharedStrings[$sharedIndex] ?? '');
        }

        if ($type === 'inlineStr') {
            $textNodes = $this->spreadsheetXPath($cell, './s:is/s:t');

            if ($textNodes !== []) {
                return implode('', array_map('strval', $textNodes));
            }

            $runTextNodes = $this->spreadsheetXPath($cell, './s:is/s:r/s:t');

            return implode('', array_map('strval', $runTextNodes));
        }

        return $raw;
    }

    private function spreadsheetXPath(\SimpleXMLElement $element, string $expression): array
    {
        $element->registerXPathNamespace('s', self::SPREADSHEET_MAIN_NAMESPACE);

        return $element->xpath($expression) ?: [];
    }
}
