<?php

namespace Tests\Feature;

use App\Models\Flash;
use App\Models\FlashGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FlashApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_standalone_flash(): void
    {
        $response = $this->postJson('/api/flashes', [
            'vocabulary' => 'ni hao',
            'pinyin' => 'ni hao',
            'examples' => [
                [
                    'sentence' => 'Ni hao ma?',
                    'pinyin' => 'Nǐ hǎo ma?',
                    'translation_vi' => 'Bạn khỏe không?',
                ],
            ],
            'group_mode' => 'solo',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('flash.vocabulary', 'ni hao')
            ->assertJsonPath('flash.examples.0.sentence', 'Ni hao ma?')
            ->assertJsonPath('flash.group_id', null)
            ->assertJsonPath('group', null);

        $this->assertDatabaseHas('flashes', [
            'vocabulary' => 'ni hao',
            'group_id' => null,
        ]);
        $this->assertDatabaseHas('flash_examples', [
            'sentence' => 'Ni hao ma?',
            'translation_vi' => 'Bạn khỏe không?',
        ]);
    }

    public function test_it_creates_a_flash_with_a_new_random_group(): void
    {
        $response = $this->postJson('/api/flashes', [
            'vocabulary' => 'xie xie',
            'pinyin' => 'xie xie',
            'examples' => [
                [
                    'sentence' => 'Xie xie ni.',
                    'pinyin' => 'Xiè xie nǐ.',
                    'translation_vi' => 'Cảm ơn bạn.',
                ],
            ],
            'group_mode' => 'new',
        ]);

        $groupId = $response->json('group.id');

        $response
            ->assertCreated()
            ->assertJsonPath('flash.group_id', $groupId);

        $this->assertNotNull($groupId);
        $this->assertDatabaseHas('flash_groups', [
            'id' => $groupId,
        ]);
        $this->assertDatabaseHas('flashes', [
            'vocabulary' => 'xie xie',
            'group_id' => $groupId,
        ]);
    }

    public function test_it_adds_a_flash_to_an_existing_group(): void
    {
        $group = FlashGroup::query()->create([
            'id' => 'group-001',
        ]);

        $response = $this->postJson('/api/flashes', [
            'vocabulary' => 'zaijian',
            'group_mode' => 'existing',
            'group_id' => $group->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('flash.group_id', $group->id);

        $this->assertDatabaseHas('flashes', [
            'vocabulary' => 'zaijian',
            'group_id' => $group->id,
        ]);
    }

    public function test_it_lists_recent_flashes(): void
    {
        FlashGroup::query()->create([
            'id' => 'group-001',
        ]);

        Flash::query()->create([
            'vocabulary' => 'hao',
            'pinyin' => 'hao',
            'group_id' => 'group-001',
        ])->examples()->create([
            'sentence' => 'Hao jiu bu jian.',
            'pinyin' => 'Hǎo jiǔ bú jiàn.',
            'translation_vi' => 'Lâu rồi không gặp.',
        ]);

        $response = $this->getJson('/api/flashes');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vocabulary', 'hao')
            ->assertJsonPath('data.0.examples.0.translation_vi', 'Lâu rồi không gặp.')
            ->assertJsonPath('groups.0.id', 'group-001')
            ->assertJsonPath('groups.0.flash_count', 1)
            ->assertJsonPath('ungrouped_count', 0);
    }

    public function test_it_filters_flashes_by_group_and_ungrouped_state(): void
    {
        FlashGroup::query()->create([
            'id' => 'group-001',
        ]);
        FlashGroup::query()->create([
            'id' => 'group-002',
        ]);

        Flash::query()->create([
            'vocabulary' => 'hao',
            'pinyin' => 'hao',
            'group_id' => 'group-001',
        ]);
        Flash::query()->create([
            'vocabulary' => 'xie xie',
            'pinyin' => 'xie xie',
            'group_id' => 'group-002',
        ]);
        Flash::query()->create([
            'vocabulary' => 'zaijian',
            'pinyin' => 'zaijian',
            'group_id' => null,
        ]);

        $groupResponse = $this->getJson('/api/flashes?group_id=group-001');

        $groupResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vocabulary', 'hao')
            ->assertJsonPath('data.0.group_id', 'group-001')
            ->assertJsonPath('groups.0.id', 'group-001')
            ->assertJsonPath('groups.1.id', 'group-002')
            ->assertJsonPath('ungrouped_count', 1);

        $ungroupedResponse = $this->getJson('/api/flashes?ungrouped=1');

        $ungroupedResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vocabulary', 'zaijian')
            ->assertJsonPath('data.0.group_id', null)
            ->assertJsonPath('ungrouped_count', 1);
    }

    public function test_it_updates_an_existing_flash(): void
    {
        $group = FlashGroup::query()->create([
            'id' => 'group-edit',
        ]);

        $flash = Flash::query()->create([
            'vocabulary' => 'old',
            'pinyin' => 'old',
        ]);
        $flash->examples()->create([
            'sentence' => 'old sentence',
            'pinyin' => 'old pinyin',
            'translation_vi' => 'cau cu',
        ]);

        $response = $this->putJson("/api/flashes/{$flash->id}", [
            'vocabulary' => 'new value',
            'pinyin' => 'xin',
            'examples' => [
                [
                    'sentence' => 'new sentence',
                    'pinyin' => 'xin juzi',
                    'translation_vi' => 'cau moi',
                ],
                [
                    'sentence' => 'second sentence',
                    'pinyin' => null,
                    'translation_vi' => 'cau thu hai',
                ],
            ],
            'group_mode' => 'existing',
            'group_id' => $group->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('flash.vocabulary', 'new value')
            ->assertJsonPath('flash.group_id', $group->id)
            ->assertJsonPath('flash.examples.1.sentence', 'second sentence');

        $this->assertDatabaseHas('flashes', [
            'id' => $flash->id,
            'vocabulary' => 'new value',
            'group_id' => $group->id,
        ]);
        $this->assertDatabaseHas('flash_examples', [
            'flash_id' => $flash->id,
            'sentence' => 'new sentence',
            'translation_vi' => 'cau moi',
        ]);
        $this->assertDatabaseMissing('flash_examples', [
            'flash_id' => $flash->id,
            'sentence' => 'old sentence',
        ]);
    }

    public function test_it_deletes_an_existing_flash(): void
    {
        $flash = Flash::query()->create([
            'vocabulary' => 'to-delete',
            'pinyin' => 'xoa',
        ]);
        $flash->examples()->create([
            'sentence' => 'delete me',
            'pinyin' => null,
            'translation_vi' => 'xoa di',
        ]);

        $response = $this->deleteJson("/api/flashes/{$flash->id}");

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Flash deleted successfully.');

        $this->assertDatabaseMissing('flashes', [
            'id' => $flash->id,
        ]);
        $this->assertDatabaseMissing('flash_examples', [
            'flash_id' => $flash->id,
            'sentence' => 'delete me',
        ]);
    }

    public function test_it_imports_grouped_rows_into_one_flash_with_examples(): void
    {
        $csv = implode("\n", [
            'vocabulary,pinyin,group_id,example_sentence,example_pinyin,example_translation_vi',
            '你好,nǐ hǎo,group-001,你好嗎？,Nǐ hǎo ma?,Bạn khỏe không?',
            '你好,nǐ hǎo,group-001,很高興認識你。,Hěn gāoxìng rènshi nǐ.,Rất vui được gặp bạn.',
            '谢谢,xiè xie,group-001,謝謝你。,Xiè xie nǐ.,Cảm ơn bạn.',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            name: 'flashes.csv',
            content: $csv,
        );

        $response = $this->post('/api/flashes/import', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('merged', 0)
            ->assertJsonPath('examples_created', 3)
            ->assertJsonPath('skipped', 0);

        $this->assertDatabaseHas('flash_groups', [
            'id' => 'group-001',
        ]);
        $this->assertDatabaseHas('flashes', [
            'vocabulary' => '你好',
            'group_id' => 'group-001',
        ]);
        $this->assertSame(2, Flash::query()->count());
        $this->assertSame(2, Flash::query()->where('vocabulary', '你好')->firstOrFail()->examples()->count());
        $this->assertDatabaseHas('flash_examples', [
            'sentence' => '你好嗎？',
            'translation_vi' => 'Bạn khỏe không?',
        ]);
        $this->assertDatabaseHas('flash_examples', [
            'sentence' => '很高興認識你。',
            'translation_vi' => 'Rất vui được gặp bạn.',
        ]);
    }

    public function test_it_imports_same_vocabulary_into_multiple_groups_as_separate_flashes(): void
    {
        $csv = implode("\n", [
            'vocabulary,pinyin,group_id,example_sentence,example_pinyin,example_translation_vi',
            '你好,nǐ hǎo,group-001,你好嗎？,Nǐ hǎo ma?,Bạn khỏe không?',
            '你好,nǐ hǎo,group-009,很高興認識你。,Hěn gāoxìng rènshi nǐ.,Rất vui được gặp bạn.',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            name: 'flashes-conflict.csv',
            content: $csv,
        );

        $response = $this->post('/api/flashes/import', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('merged', 0)
            ->assertJsonPath('examples_created', 2)
            ->assertJsonPath('skipped', 0);

        $this->assertSame(2, Flash::query()->count());
        $this->assertDatabaseHas('flashes', [
            'vocabulary' => '你好',
            'group_id' => 'group-001',
        ]);
        $this->assertDatabaseHas('flashes', [
            'vocabulary' => '你好',
            'group_id' => 'group-009',
        ]);
        $this->assertDatabaseHas('flash_examples', [
            'sentence' => '你好嗎？',
            'translation_vi' => 'Bạn khỏe không?',
        ]);
        $this->assertDatabaseHas('flash_examples', [
            'sentence' => '很高興認識你。',
            'translation_vi' => 'Rất vui được gặp bạn.',
        ]);
    }

    public function test_it_merges_existing_flash_only_when_vocabulary_and_group_id_both_match(): void
    {
        FlashGroup::query()->create([
            'id' => 'group-db',
        ]);
        FlashGroup::query()->create([
            'id' => 'group-import',
        ]);

        $flash = Flash::query()->create([
            'vocabulary' => '你好',
            'pinyin' => 'nǐ hǎo',
            'group_id' => 'group-db',
        ]);

        $csv = implode("\n", [
            'vocabulary,pinyin,group_id,example_sentence,example_pinyin,example_translation_vi',
            '你好,nǐ hǎo,group-db,謝謝你。,Xiè xie nǐ.,Cảm ơn bạn.',
            '你好,nǐ hǎo,group-import,你好嗎？,Nǐ hǎo ma?,Bạn khỏe không?',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            name: 'flashes-db-group.csv',
            content: $csv,
        );

        $response = $this->post('/api/flashes/import', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('merged', 1)
            ->assertJsonPath('examples_created', 2);

        $flash->refresh();

        $this->assertSame('group-db', $flash->group_id);
        $this->assertDatabaseHas('flash_examples', [
            'flash_id' => $flash->id,
            'sentence' => '謝謝你。',
            'translation_vi' => 'Cảm ơn bạn.',
        ]);
        $this->assertDatabaseHas('flashes', [
            'vocabulary' => '你好',
            'group_id' => 'group-import',
        ]);

        $importFlash = Flash::query()
            ->where('vocabulary', '你好')
            ->where('group_id', 'group-import')
            ->firstOrFail();

        $this->assertDatabaseHas('flash_examples', [
            'flash_id' => $importFlash->id,
            'sentence' => '你好嗎？',
            'translation_vi' => 'Bạn khỏe không?',
        ]);
    }
}
