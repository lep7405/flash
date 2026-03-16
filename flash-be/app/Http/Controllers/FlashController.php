<?php

namespace App\Http\Controllers;

use App\Models\Flash;
use App\Models\FlashExample;
use App\Models\FlashGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlashController extends Controller
{
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
}
