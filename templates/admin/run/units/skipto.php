<?php echo $prepend ?>

<div class="padding-below">
    <label>Skip to the position returned by this R code: <br>
        <textarea style="width:388px;" data-editor="r" class="form-control col-md-5" name="condition" rows="4" placeholder="Return a position number, e.g.: if (survey1$group == 1) 30 else 50"><?= $condition ?></textarea>
    </label><br />

    <p class="help-block" style="max-width:388px;">
        Return a single number — the position to jump to (shown next to each
        unit). No unit at that position? The next one after it is used. A
        non-numeric result just continues to the next unit.
        <br>
        <small class="text-muted">Positions are literal: they are not rebased
        when the run is imported with an offset or units are renumbered.</small>
    </p>
</div>
<div class="clear clearfix"></div>
<br />
<div class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipTo">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipTo">Test</a>
</div>
<p>&nbsp;</p>
