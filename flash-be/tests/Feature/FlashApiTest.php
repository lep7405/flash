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
        Flash::query()->create([
            'vocabulary' => 'hao',
            'pinyin' => 'hao',
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
            ->assertJsonPath('data.0.examples.0.translation_vi', 'Lâu rồi không gặp.');
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

    public function test_it_rejects_group_conflicts_with_row_numbers_before_writing_to_db(): void
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
            ->assertStatus(422)
            ->assertJsonPath('conflicts.0.vocabulary', '你好')
            ->assertJsonPath('conflicts.0.rows.0', 2)
            ->assertJsonPath('conflicts.0.rows.1', 3);

        $this->assertSame(0, Flash::query()->count());
        $this->assertSame(0, FlashGroup::query()->count());
    }

    public function test_it_uses_existing_db_group_id_when_import_group_id_differs(): void
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
            ->assertJsonPath('created', 0)
            ->assertJsonPath('merged', 1)
            ->assertJsonPath('examples_created', 1);

        $flash->refresh();

        $this->assertSame('group-db', $flash->group_id);
        $this->assertDatabaseHas('flash_examples', [
            'flash_id' => $flash->id,
            'sentence' => '你好嗎？',
            'translation_vi' => 'Bạn khỏe không?',
        ]);
    }
}
