<?php
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract for sunsetting the Shuffle unit in favour of the
 * Randomiser (a premade one-item survey with a `calculate` item doing
 * `sample(1:N, 1)`; see AdminAjaxController::ajaxCreateRandomiser and the
 * run-editor add menu in AdminRunController::getUnitAddButtons).
 *
 * Shuffle is *soft*-deprecated: removed from the add menu, but the class,
 * its tables, the `shuffle$group` data frame, and its exports stay so
 * existing runs keep working. These reflection-level checks are CI-safe
 * (no DB / Site). The end-to-end behaviour — a Randomiser survey being
 * created, attached to the run, auto-finalising, and exposing
 * `surveyname$group` — is exercised against the dev instance via the
 * Playwright/Chrome flow described in the change's verification notes.
 */
class RandomiserTest extends TestCase
{
    public function testShuffleStaysRegisteredForExistingRuns(): void
    {
        $units = RunUnitFactory::getSupportedUnits();
        $this->assertContains(
            'Shuffle',
            $units,
            'Shuffle must remain a supported unit type so existing runs keep loading and executing.'
        );
    }

    public function testRandomiserIsNotARunUnitType(): void
    {
        $units = RunUnitFactory::getSupportedUnits();
        $this->assertNotContains(
            'Randomiser',
            $units,
            'Randomiser is a premade survey, not a RunUnit type — it must not be a factory-supported unit.'
        );
    }

    public function testRandomiserAjaxRouteResolves(): void
    {
        // `ajax_create_randomiser` -> getPrivateAction -> ajaxCreateRandomiser.
        $rc = new ReflectionClass(AdminAjaxController::class);
        $this->assertTrue(
            $rc->hasMethod('ajaxCreateRandomiser'),
            'The ajax_create_randomiser route must map to AdminAjaxController::ajaxCreateRandomiser.'
        );
    }

    public function testShuffleClassRemainsFunctional(): void
    {
        $rc = new ReflectionClass('Shuffle');
        $this->assertTrue($rc->hasMethod('selectRandomGroup'), 'Shuffle execution path must be retained.');
        $this->assertTrue($rc->hasMethod('getUnitSessionOutput'), 'Shuffle execution path must be retained.');
    }
}
