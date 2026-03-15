<?php

namespace Tests\Feature;

use App\Models\Flash;
use App\Models\FlashGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
