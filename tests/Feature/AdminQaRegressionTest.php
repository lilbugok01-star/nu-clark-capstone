<?php

namespace Tests\Feature;

use App\Models\EventBudget;
use App\Models\EventPayment;
use App\Models\FileHuntingSignatory;
use Tests\Support\IsolatedDatabaseTestCase;

class AdminQaRegressionTest extends IsolatedDatabaseTestCase
{
    public function test_payment_form_submits_the_expected_transaction_type(): void
    {
        $event = $this->event();
        $this->actingAs($this->user('admin'));
        $html = $this->get(route('admin.event.payments', $event->id))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        foreach (['income', 'expense'] as $type) {
            $radio = $xpath->query("//input[@type='radio' and @value='{$type}']")->item(0);
            $this->assertNotNull($radio);
            $this->post(route('admin.event.payments.store', $event->id), [
                $radio->getAttribute('name') => $type,
                'amount' => '900.25', 'description' => 'QA transaction',
                'payment_date' => '2026-09-15', 'payment_method' => 'Cash',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('event_payments', ['event_id' => $event->id, 'payment_type' => $type, 'amount' => 900.25]);
        }
        $this->assertDatabaseCount('event_payments', 2);
        $response = $this->get(route('admin.event.payments', $event->id))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        foreach (['Income', 'Expense'] as $label) {
            $this->assertSame(1, $xpath->query("//tbody//span[normalize-space(.)='{$label}']")->length);
        }
        $this->writeBrowserFixture('payments', $response->getContent());
    }

    public function test_budget_accepts_the_reported_large_amount_and_decimal_boundary(): void
    {
        $event = $this->event();
        $this->actingAs($this->user('admin'));
        $this->post(route('admin.event.budget.store', $event->id), $this->budget([
            'estimated_amount' => '9043432642', 'actual_amount' => '9043432642.00',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $item = EventBudget::firstOrFail();
        $this->assertSame('9043432642.00', $item->estimated_amount);
        $this->assertSame('9043432642.00', $item->actual_amount);
        $this->put(route('admin.budget.update', $item), $this->budget([
            'estimated_amount' => '9999999999.99', 'actual_amount' => null,
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('9999999999.99', $item->fresh()->estimated_amount);
        $this->assertNull($item->fresh()->actual_amount);
        $response = $this->get(route('admin.event.budget', $event->id))->assertOk();
        $this->writeBrowserFixture('budget', $response->getContent());
    }

    public function test_invalid_budget_amounts_are_rejected_without_writing_or_changing_records(): void
    {
        $event = $this->event();
        $item = EventBudget::create($this->budget(['event_id' => $event->id]));
        $this->actingAs($this->user('admin'));
        foreach (['10000000000', '9999999999.999', '1.001', '-1', 'not-a-number'] as $amount) {
            foreach (['estimated_amount', 'actual_amount'] as $field) {
                $payload = $this->budget([$field => $amount]);
                $this->postJson(route('admin.event.budget.store', $event->id), $payload)
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->putJson(route('admin.budget.update', $item), $payload)
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertSame('100.00', $item->fresh()->estimated_amount);
                $this->assertSame('50.00', $item->fresh()->actual_amount);
                $this->assertDatabaseCount('event_budgets', 1);
            }
        }
    }

    public function test_payment_amounts_are_bounded_before_database_insert(): void
    {
        $event = $this->event();
        $this->actingAs($this->user('admin'));
        $payload = ['payment_type' => 'expense', 'description' => 'QA payment', 'payment_date' => '2026-09-15'];
        foreach (['10000000000', '9999999999.999', '1.001', '0', '-1'] as $amount) {
            $this->postJson(route('admin.event.payments.store', $event->id), $payload + ['amount' => $amount])
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->assertDatabaseCount('event_payments', 0);
        foreach (['0.01', '9043432642', '9999999999.99'] as $amount) {
            $this->post(route('admin.event.payments.store', $event->id), $payload + ['amount' => $amount])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('9999999999.99', EventPayment::latest('id')->first()->amount);
    }

    public function test_file_hunting_get_never_saves_and_explicit_save_persists_configuration(): void
    {
        $this->actingAs($this->user('admin'));
        $before = FileHuntingSignatory::orderBy('step_order')->get()->toArray();
        $this->get(route('admin.file-hunting'))->assertOk();
        $this->get(route('admin.file-hunting').'?signatories[0][position_label]=Unsaved')->assertOk();
        $this->assertSame($before, FileHuntingSignatory::orderBy('step_order')->get()->toArray());
        $this->from(route('admin.file-hunting'))->post(route('admin.file-hunting.save'), ['signatories' => [
            ['role' => 'dean', 'position_label' => 'QA Saved Dean', 'is_active' => '1'],
            ['role' => 'adviser', 'position_label' => 'QA Inactive Adviser'],
        ]])->assertRedirect(route('admin.file-hunting'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('file_hunting_signatories', 2);
        $this->assertDatabaseHas('file_hunting_signatories', ['step_order' => 1, 'position_label' => 'QA Saved Dean', 'is_active' => true]);
        $this->assertDatabaseHas('file_hunting_signatories', ['step_order' => 2, 'position_label' => 'QA Inactive Adviser', 'is_active' => false]);
        $response = $this->get(route('admin.file-hunting'))->assertOk()->assertSee('QA Saved Dean');
        $this->writeBrowserFixture('file-hunting', $response->getContent());
    }

    private function writeBrowserFixture(string $name, string $html): void
    {
        // Optional rendered fixtures let browser checks run without using an application database.
        $directory = getenv('ADMIN_QA_FIXTURE_DIR');
        if ($directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    private function budget(array $overrides = []): array
    {
        return array_replace([
            'category' => 'Venue', 'description' => 'QA budget',
            'estimated_amount' => '100.00', 'actual_amount' => '50.00', 'status' => 'planned',
        ], $overrides);
    }
}
