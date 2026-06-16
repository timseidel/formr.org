<?php

/**
 * SkipTo — a computed jump unit.
 *
 * Like SkipForward/SkipBackward it evaluates an R expression, but instead
 * of coercing the result to a boolean and jumping to a fixed `if_true`
 * position, it interprets the result as a NUMERIC absolute run position
 * and jumps the participant there. One SkipTo replaces a stack of Skips.
 *
 * Reuses the `survey_branches` table (the R code lives in `condition`;
 * `if_true` / `automatically_*` are unused). The target is resolved via
 * Run::resolvePositionAtOrAfter(): if the exact position has no unit
 * (positions are sparse), it snaps forward to the next existing one.
 */
class SkipTo extends Branch {

    public $type = 'SkipTo';
    public $icon = 'fa-arrows-alt';

    /**
     * An array of unit's exportable attributes. No `if_true` /
     * `automatically_*` — the destination is computed at runtime.
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'condition');

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'condition' => $this->condition,
            'position' => $this->position,
        ));

        return parent::runDialog($dialog);
    }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        $data = ['expire_relatively' => null, 'check_failed' => false];

        if (trim((string) $this->condition) === '') {
            // A blank SkipTo has no destination. It's a routing-only unit
            // with no participant UI, so don't send empty code to OpenCPU
            // and strand the participant in a wait state — just continue.
            $data['log'] = $this->getLogMessage('skipto_invalid', 'SkipTo unit: no R code given. Continuing to next unit.');
            $data['end_session'] = $data['move_on'] = true;
            $this->outputData = $data;
            return $data;
        }

        $opencpu_vars = $unitSession->getRunData($this->condition);
        $eval = opencpu_evaluate($this->condition, $opencpu_vars);

        if ($eval === null) {
            $error = (string) opencpu_last_error();
            notify_study_admin($unitSession, 'SkipTo unit: OpenCPU error evaluating condition. ' . $error, 'error');
            $data['log'] = $this->getLogMessage('error_opencpu_r', "OpenCPU error. Fix R code \n\n" . $error);
            $data['wait_opencpu'] = true;
            $this->outputData = $data;
            return $data;
        }

        if (is_array($eval)) {
            $eval = array_shift($eval);
            $data['log'] = $this->getLogMessage('opencpu_result_warn', "Your R code returned more than one result. Please return a single position number.");
        }

        $target = is_numeric($eval) ? (int) round((float) $eval) : null;
        $position = $target === null ? null : $this->run->resolvePositionAtOrAfter($target);

        if ($position !== null) {
            // Computed a valid (possibly snapped) destination — jump.
            $data['log'] = ($position !== $target)
                ? $this->getLogMessage('skipto_snap', "No unit at position $target; jumping to next existing position $position.")
                : $this->getLogMessage('skipto_jump', "Jumping to position $position.");
            $data['end_session'] = true;
            $data['run_to'] = $position;
        } else {
            // Non-numeric result, or no position at/after the target.
            // Never strand the participant: log, notify, and move on.
            $reason = ($target === null)
                ? "SkipTo unit: R code did not return a numeric position (got: " . stringBool($eval) . "). Continuing to next unit."
                : "SkipTo unit: no run position at or after $target. Continuing to next unit.";
            notify_study_admin($unitSession, $reason, 'error');
            $data['log'] = $this->getLogMessage('skipto_invalid', $reason);
            $data['end_session'] = $data['move_on'] = true;
        }

        $this->outputData = $data;
        return $data;
    }

    public function test() {
        $results = $this->getSampleSessions();
        if (!$results) {
            $this->noTestSession();
            return null;
        }

        $test_tpl = '
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Code (Position)</th>
                        <th>R result</th>
                        <th>Jumps to</th>
                    </tr>
                    %{rows}
                </thead>
            </table>
        ';

        $row_tpl = '
            <tr>
                <td style="word-wrap:break-word;max-width:150px"><small>%{session} (%{position})</small></td>
                <td>%{result}</td>
                <td>%{target}</td>
            </tr>
        ';

        // Show the first session's full OpenCPU debug output.
        $unitSession = current($results);
        $opencpu_vars = $unitSession->getRunData($this->condition);
        $ocpu_session = opencpu_evaluate($this->condition, $opencpu_vars, 'text', null, true);
        $output = opencpu_debug($ocpu_session, null, 'text');

        $rows = '';
        foreach ($results as $unitSession) {
            $opencpu_vars = $unitSession->getRunData($this->condition);
            $eval = opencpu_evaluate($this->condition, $opencpu_vars);
            if (is_array($eval)) {
                $eval = array_shift($eval);
            }
            $target = is_numeric($eval) ? (int) round((float) $eval) : null;
            $position = $target === null ? null : $this->run->resolvePositionAtOrAfter($target);

            if ($position === null) {
                $destination = 'continue (invalid)';
            } elseif ($position !== $target) {
                $destination = "$position (snapped from $target)";
            } else {
                $destination = (string) $position;
            }

            $rows .= Template::replace($row_tpl, array(
                'session' => $unitSession->runSession->session,
                'position' => $unitSession->runSession->position,
                'result' => stringBool($eval),
                'target' => $destination,
            ));
        }

        $output .= Template::replace($test_tpl, array('rows' => $rows));

        return $output;
    }

}
