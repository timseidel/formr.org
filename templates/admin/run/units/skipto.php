<?php echo $prepend ?>

<div class="padding-below">
    <label>Go to the position returned by this R code: <br>
        <textarea style="width:388px;" data-editor="r" class="form-control col-md-5" name="condition" rows="4" placeholder="Return a position number, e.g.: if (survey1$group == 1) 30 else 50"><?= $condition ?></textarea>
    </label><br />

    <p class="help-block" style="max-width:388px;">
        The R code must return a single number: the run position to jump to
        (the numbers shown next to each unit). If no unit sits at that exact
        position, the participant is sent to the next existing position after
        it. If the code returns a non-numeric value, the participant simply
        continues to the next unit.
        <br><br>
        <strong>Note:</strong> these position numbers are literal — they are
        not automatically rebased when the run is imported with an offset or
        when units are renumbered. Reference stable positions.
    </p>
</div>
<div class="clear clearfix"></div>
<br />
<div class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipTo">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipTo">Test</a>
</div>
<p>&nbsp;</p>
