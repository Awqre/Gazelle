<?
//Include the header
View::show_header('Rule Index');
?>
<!-- General Rules -->
<div class="thin">
<? include('jump.php'); ?>
	<div class="header">
		<h2 id="general">Golden Rules</h2>
		<p>The Golden Rules encompass all of <? echo SITE_NAME; ?> and our IRC Network. These rules are paramount; non-compliance will jeopardize your account.</p>
	</div>
	<div class="box pad rule_summary" style="padding: 10px 10px 10px 20px;">
<? Rules::display_golden_rules(); ?>
	</div>
	<!-- END General Rules -->

</div>
<?
View::show_footer();
?>
