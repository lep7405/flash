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

        $flashes = Flash::query()
            ->with('examples')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Flash $flash) => $this->flashPayload($flash))
            ->all();

        return response()->json([
            'data' => $flashes,
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

        try {
            $rows = $extension === 'csv'
                ? $this->parseCsvRows($file)
                : $this->parseXlsxRows($file);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($rows === []) {
            return response()->json([
                'message' => 'Import file is empty.',
            ], 422);
        }

        $header = array_map(fn ($value) => $this->normalizeHeader($value), $rows[0]);

        if ($header !== self::IMPORT_HEADERS) {
            return response()->json([
                'message' => 'Invalid header. Please use the required template columns in exact order.',
                'required_header' => self::IMPORT_HEADERS,
            ], 422);
        }

        $dataRows = array_slice($rows, 1);
        $createdCount = 0;
        $mergedCount = 0;
        $exampleCreatedCount = 0;
        $skippedCount = 0;

        try {
            info('row',[
               'data' => $dataRows,
            ]);
            $groupedVocabulary = $this->groupImportRows($dataRows, $skippedCount);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $conflicts = $this->detectImportGroupConflicts($groupedVocabulary);

        if ($conflicts !== []) {
            return response()->json([
                'message' => implode(' || ', array_map(
                    fn (array $conflict): string => $conflict['message'],
                    $conflicts,
                )),
                'conflicts' => $conflicts,
            ], 422);
        }

        DB::transaction(function () use ($groupedVocabulary, &$createdCount, &$mergedCount, &$exampleCreatedCount): void {
            $existingFlashes = $this->loadExistingFlashesByVocabularyKey();

            foreach ($groupedVocabulary as $vocabularyKey => $group) {
                $flash = $existingFlashes[$vocabularyKey] ?? null;
                $resolvedGroupId = $this->resolveImportGroupId(
                    dbGroupId: $flash?->group_id,
                    importGroupId: $group['group_id'],
                );

                if ($resolvedGroupId !== null) {
                    FlashGroup::query()->firstOrCreate(['id' => $resolvedGroupId]);
                }

                if (! $flash) {
                    $flash = Flash::query()->create([
                        'vocabulary' => $group['vocabulary'],
                        'pinyin' => $group['pinyin'],
                        'group_id' => $resolvedGroupId,
                    ]);

                    $flash->setRelation('examples', collect());
                    $existingFlashes[$vocabularyKey] = $flash;
                    $createdCount++;
                } else {
                    $mergedCount++;
                    $updates = [];

                    if (($flash->pinyin === null || trim((string) $flash->pinyin) === '') && $group['pinyin'] !== null) {
                        $updates['pinyin'] = $group['pinyin'];
                    }

                    if ($resolvedGroupId !== $flash->group_id) {
                        $updates['group_id'] = $resolvedGroupId;
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
        return DB::transaction(function () use ($flash, $data): array {
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

    private function groupImportRows(array $dataRows, int &$skippedCount): array
    {
        $groupedVocabulary = [];

        foreach ($dataRows as $index => $cells) {
            $line = $index + 2;
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
                continue;
            }

            if ($vocabulary === null) {
                throw new RuntimeException("Row {$line}: vocabulary is required.");
            }

            if ($exampleSentence === null && ($examplePinyin !== null || $exampleTranslation !== null)) {
                throw new RuntimeException(
                    "Row {$line}: example_sentence is required when example columns are filled.",
                );
            }

            $vocabularyKey = $this->normalizeVocabularyKey($vocabulary);
            info('vocabulary: ' . $vocabulary);

            if (! isset($groupedVocabulary[$vocabularyKey])) {
                $groupedVocabulary[$vocabularyKey] = [
                    'vocabulary' => $vocabulary,
                    'rows' => [],
                    'group_ids' => [],
                    'group_id' => null,
                    'pinyin' => null,
                    'examples' => [],
                ];
            }
            info('$groupedVocabulary: ',[
                '$groupedVocabulary' => $groupedVocabulary,
            ]);

            $groupedVocabulary[$vocabularyKey]['rows'][] = $line;

            if ($groupedVocabulary[$vocabularyKey]['pinyin'] === null && $pinyin !== null) {
                $groupedVocabulary[$vocabularyKey]['pinyin'] = $pinyin;
            }

            if ($groupId !== null) {
                if (! isset($groupedVocabulary[$vocabularyKey]['group_ids'][$groupId])) {
                    $groupedVocabulary[$vocabularyKey]['group_ids'][$groupId] = [];
                }

                $groupedVocabulary[$vocabularyKey]['group_ids'][$groupId][] = $line;

                if ($groupedVocabulary[$vocabularyKey]['group_id'] === null) {
                    $groupedVocabulary[$vocabularyKey]['group_id'] = $groupId;
                }
            }

            if ($exampleSentence !== null) {
                $groupedVocabulary[$vocabularyKey]['examples'][] = [
                    'line' => $line,
                    'sentence' => $exampleSentence,
                    'pinyin' => $examplePinyin,
                    'translation_vi' => $exampleTranslation,
                ];
            }
        }

        return $groupedVocabulary;
    }

    private function detectImportGroupConflicts(array $groupedVocabulary): array
    {
        $conflicts = [];

        foreach ($groupedVocabulary as $group) {
            if (count($group['group_ids']) <= 1) {
                continue;
            }

            $parts = [];

            foreach ($group['group_ids'] as $groupId => $lines) {
                $parts[] = "{$groupId} (rows: ".implode(', ', $lines).')';
            }

            $conflicts[] = [
                'vocabulary' => $group['vocabulary'],
                'rows' => $group['rows'],
                'message' => sprintf(
                    'Vocabulary "%s" has multiple group_id values: %s',
                    $group['vocabulary'],
                    implode(' | ', $parts),
                ),
            ];
        }

        return $conflicts;
    }

    private function loadExistingFlashesByVocabularyKey(): array
    {
        $flashes = [];

        foreach (Flash::query()->with('examples')->get() as $flash) {
            $flashes[$this->normalizeVocabularyKey($flash->vocabulary)] = $flash;
        }

        return $flashes;
    }

    private function resolveImportGroupId(?string $dbGroupId, ?string $importGroupId): ?string
    {
        return $dbGroupId ?? $importGroupId;
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

    private function parseCsvRows(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Cannot read uploaded csv file.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open uploaded csv file.');
        }

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function parseXlsxRows(UploadedFile $file): array
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
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Uploaded xlsx has no sheet1.');
        }

        $xml = simplexml_load_string($sheetXml);

        if ($xml === false || ! isset($xml->sheetData)) {
            throw new RuntimeException('Uploaded xlsx is invalid.');
        }

        $rows = [];

        foreach ($xml->sheetData->row as $rowNode) {
            $row = [];

            foreach ($rowNode->c as $cell) {
                $reference = (string) $cell['r'];
                $columnIndex = $this->columnIndexFromReference($reference);
                $row[$columnIndex] = $this->extractXlsxCellValue($cell, $sharedStrings);
            }

            if ($row !== []) {
                $maxColumnIndex = max(array_keys($row));
                $normalized = array_fill(0, $maxColumnIndex + 1, '');

                foreach ($row as $index => $value) {
                    $normalized[$index] = $value;
                }

                $rows[] = $normalized;
            }
        }

        return $rows;
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

        foreach ($xml->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
                continue;
            }

            $text = '';
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
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
        $raw = isset($cell->v) ? (string) $cell->v : '';

        if ($type === 's') {
            $sharedIndex = (int) $raw;

            return (string) ($sharedStrings[$sharedIndex] ?? '');
        }

        if ($type === 'inlineStr' && isset($cell->is->t)) {
            return (string) $cell->is->t;
        }

        return $raw;
    }
}
