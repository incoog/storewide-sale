<?php
// Destructive fixtures: explicitly opt in and use a marked, empty test database.
if ( PHP_SAPI !== 'cli' || getenv( 'SWS_TEST_RUN' ) !== '1' ) {
    http_response_code( 403 );
    exit( 'Integration tests require CLI and SWS_TEST_RUN=1.' );
}
if ( ! defined( 'ABSPATH' ) ) {
    $loader = getenv( 'SWS_WP_LOAD' );
    if ( ! $loader || ! is_file( $loader ) ) { throw new RuntimeException( 'Run through WP-CLI or supply SWS_WP_LOAD.' ); }
    require $loader;
}
global $wpdb;
if ( get_option( 'sws_test_environment' ) !== 'disposable' || get_option( 'sws_campaign' ) || (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ('product','product_variation')" ) !== 0 ) {
    throw new RuntimeException( 'Use an empty, disposable test site marked with sws_test_environment=disposable.' );
}
$soft79_file = getenv( 'SWS_SOFT79_FILE' );
if ( ! $soft79_file || ! is_file( $soft79_file ) ) { throw new RuntimeException( 'Set SWS_SOFT79_FILE to the separately checked out fork entry point.' ); }
set_error_handler( function ( $severity, $message, $file, $line ) {
    if ( error_reporting() & $severity && strpos( $file, '/storewide-sale/' ) !== false && strpos( $file, '/tests/' ) === false ) {
        throw new ErrorException( $message, 0, $severity, $file, $line );
    }
    return false;
} );
wp_set_current_user( 1 );
SWS_Store::install();
echo 'ENV WP=' . get_bloginfo('version') . ' Woo=' . WC_VERSION . ' PHP=' . PHP_VERSION . "\n";
global $n;
$n=0;
function ok($condition,$label) { global $n; if (!$condition) { throw new Exception('FAIL '.$label); } $n++; echo 'PASS '.$label."\n"; }
function product($name,$regular,$sale='',$date='2020-01-01') {
    $p=new WC_Product_Simple(); $p->set_name($name); $p->set_status('publish'); $p->set_date_created($date); $p->set_regular_price($regular); $p->set_sale_price($sale); $p->save(); return $p->get_id();
}
function row($id) { $s=SWS_Store::campaign(); return SWS_Store::get($s['id'],$id,true); }
function sale($id) { return (float)wc_get_product($id)->get_sale_price('edit'); }
function run_phase($engine,$expected) {
    for($i=0;$i<50;$i++) { $s=SWS_Store::campaign(); if($s['phase']===$expected) return; $engine->process(); }
    throw new Exception('Phase did not reach '.$expected);
}
$engine=null; foreach($GLOBALS['wp_filter']['woocommerce_before_product_object_save']->callbacks[99] as $hook) { if(is_array($hook['function']) && $hook['function'][0] instanceof SWS_Engine) $engine=$hook['function'][0]; }
ok($engine instanceof SWS_Engine,'registered engine found');
ok(get_option('sws_schema_version')===SWS_VERSION,'journal schema installed');
$a=product('Existing sale',1000,900);
$b=product('Manual keep',500);
$d=product('Manual remove',600);
$c=product('Already cheaper',1000,100);
$e=product('Scheduled sale',1000,900);
$p=wc_get_product($e); $p->set_date_on_sale_from('2030-01-01'); $p->set_date_on_sale_to('2030-01-20'); $p->save(); $dates=SWS_Engine::snapshot(wc_get_product($e));
$new=product('New product',1000,'','2025-01-01');
$cat=wp_insert_term('Excluded','product_cat'); $child=wp_insert_term('Child','product_cat',array('parent'=>$cat['term_id']));
$excluded=product('Excluded category',1000); wp_set_object_terms($excluded,array($child['term_id']),'product_cat');
$variable=new WC_Product_Variable(); $variable->set_name('Variable'); $variable->set_status('publish'); $variable->set_date_created('2020-01-01'); $variable->save();
$v=new WC_Product_Variation(); $v->set_parent_id($variable->get_id()); $v->set_status('publish'); $v->set_regular_price(2000); $v->set_date_created('2025-01-01'); $v->save(); $variation=$v->get_id();
$private=product('Private product',1000);$p=wc_get_product($private);$p->set_status('private');$p->save();
$draft=product('Draft product',1000);$p=wc_get_product($draft);$p->set_status('draft');$p->save();
$external=new WC_Product_External();$external->set_name('External product');$external->set_status('publish');$external->set_regular_price(1000);$external->save();$external_id=$external->get_id();
$single=product('Excluded single',1000);$p=wc_get_product($single);$p->set_sku('EXCLUDE-SINGLE');$p->save();
$parent2=new WC_Product_Variable();$parent2->set_name('Excluded variable parent');$parent2->set_status('publish');$parent2->set_date_created('2020-01-01');$parent2->save();
$v2=new WC_Product_Variation();$v2->set_parent_id($parent2->get_id());$v2->set_status('publish');$v2->set_regular_price(800);$v2->save();$family_variation=$v2->get_id();
$v3=new WC_Product_Variation();$v3->set_parent_id($variable->get_id());$v3->set_status('publish');$v3->set_regular_price(500);$v3->save();$excluded_variation=$v3->get_id();
$private_parent=new WC_Product_Variable();$private_parent->set_name('Private parent');$private_parent->set_status('private');$private_parent->save();
$pv=new WC_Product_Variation();$pv->set_parent_id($private_parent->get_id());$pv->set_status('publish');$pv->set_regular_price(900);$pv->save();$private_child=$pv->get_id();
foreach(array($private,$draft,$external_id) as $invalid) {
    try{$engine->start(array('percent'=>20,'products'=>array($invalid)));ok(false,'unsupported exclusion rejected');}catch(RuntimeException $error){ok(true,'unsupported exclusion rejected');}
}
ok(SWS_Engine::soft79_status()==='absent','works without SOFT79');
$detect=WP_PLUGIN_DIR.'/sws-detection'; wp_mkdir_p($detect);
file_put_contents($detect.'/soft79.php',"<?php\n/*\nPlugin Name: SOFT79 Pricing Rules for WooCommerce\nText Domain: soft79-wc-pricing-rules\n*/"); wp_clean_plugins_cache();
ok(SWS_Engine::soft79_status()==='original','detects installed original without showing integration switch');
file_put_contents($detect.'/soft79.php',"<?php\n/*\nPlugin Name: SOFT79 Pricing Rules for WooCommerce\nText Domain: soft79-wc-pricing-rules\nSOFT79 Fork ID: incoog/soft79-wc-pricing-rules\n*/"); wp_clean_plugins_cache();
ok(SWS_Engine::soft79_status()==='inactive_fork','detects installed inactive fork');
unlink($detect.'/soft79.php'); rmdir($detect); wp_clean_plugins_cache();
try { $engine->start(array('percent'=>0)); ok(false,'invalid percent rejected'); } catch(RuntimeException $error) { ok(true,'invalid percent rejected'); }
// Saving a setup must never enroll products, change prices or start background work.
$settings=array('percent'=>20,'cutoff'=>'2024-12-31','categories'=>array($cat['term_id']),'products'=>array($single,$parent2->get_id(),$excluded_variation));
$before_save=SWS_Engine::snapshot(wc_get_product($a)); $scheduled=wp_next_scheduled(SWS_Engine::JOB);
$engine->save_settings($settings); $saved=SWS_Store::campaign();
ok($saved['phase']==='stopped' && empty($saved['id']),'saving settings does not create a campaign');
ok($saved['percent']===20.0 && $saved['cutoff']==='2024-12-31' && $saved['categories']===array($cat['term_id']) && $saved['products']===$settings['products'],'saves percentage date category and product choices');
ok(wp_next_scheduled(SWS_Engine::JOB)===$scheduled && SWS_Engine::same(SWS_Engine::snapshot(wc_get_product($a)),$before_save) && (int)$wpdb->get_var('SELECT COUNT(*) FROM '.SWS_Store::table())===0,'saving leaves product prices journal and scheduled work unchanged');
try { $engine->save_settings(array('percent'=>0)); ok(false,'invalid saved settings rejected'); } catch(RuntimeException $error) { ok(SWS_Store::campaign()===$saved,'invalid saved settings do not overwrite previous choices'); }
$engine->save_settings(array('percent'=>15));
ok(SWS_Store::campaign()['categories']===array() && SWS_Store::campaign()['products']===array() && SWS_Store::campaign()['cutoff']==='','saved exclusions and date can be cleared');
$engine->start($settings);
run_phase($engine,'active');
$active=SWS_Store::campaign();
try { $engine->save_settings(array('percent'=>10)); ok(false,'saving during active campaign rejected'); } catch(RuntimeException $error) { ok(SWS_Store::campaign()===$active,'saving cannot overwrite an active campaign'); }
ok(sale($a)===800.0,'20 percent saved against regular price');
ok(sale($b)===400.0,'product without original sale discounted');
ok(sale($c)===100.0 && row($c)===null,'better existing sale untouched');
ok(row($new)===null,'date exclusion');
ok(row($excluded)===null,'descendant category excluded');
ok(sale($variation)===1600.0,'variation inherits parent eligibility');
ok(row($private)===null && wc_get_product($private)->get_sale_price('edit')==='','private product not repriced');
ok(row($draft)===null && row($external_id)===null,'draft and external products not repriced');
ok(row($private_child)===null,'published variation of private parent not repriced');
ok(row($single)===null,'individual product exclusion');
ok(row($family_variation)===null,'excluding variable parent excludes all its variations');
ok(row($excluded_variation)===null && row($variation)!==null,'one variation can be excluded without excluding siblings');
ok(SWS_Engine::snapshot(wc_get_product($e))['from']===null,'original schedule suspended');
$p=wc_get_product($a); $p->set_regular_price(1200); $p->save();
ok(sale($a)===960.0,'regular change recomputes campaign sale');
ok(row($a)['conflict'] && row($a)['choice']==='','old sale creates unresolved conflict');
try { $engine->stop(); ok(false,'stop blocks conflict'); } catch(RuntimeException $error) { ok(true,'stop blocks conflict'); }
$s=SWS_Store::campaign(); $engine->decide($a,'percent',row($a)['regular'],$s['id']);
ok((float)SWS_Engine::restored_sale(row($a))===1080.0,'900 of 1000 becomes 1080 of 1200');
$p=wc_get_product($a); $p->set_regular_price(1300); $p->save();
ok(row($a)['choice']==='' && sale($a)===1040.0,'second regular change invalidates choice');
try { $engine->decide($a,'amount','1200',$s['id']); ok(false,'stale choice rejected'); } catch(RuntimeException $error) { ok(true,'stale choice rejected'); }
$p=wc_get_product($a); $p->set_regular_price(1200); $p->save(); $engine->decide($a,'percent',row($a)['regular'],$s['id']);
$p=wc_get_product($b); $p->set_sale_price(350); $p->save();
ok(row($b)['status']==='manual' && row($b)['manual_end']==='keep','manual sale leaves automation with keep default');
$p=wc_get_product($b); $p->set_regular_price(550); $p->save();
ok(sale($b)===350.0,'manual sale not recalculated on regular change');
$p=wc_get_product($d); $p->set_sale_price(390); $p->save(); $engine->decide($d,'remove',row($d)['regular'],$s['id']);
$p=wc_get_product($d); $p->set_sale_price(395); $p->save(); ok(row($d)['manual_end']==='remove','manual end preference survives another sale edit');
require $soft79_file;
ok(SWS_Engine::soft79_status()==='fork','recognizes real standalone SOFT79 fork');
$engine->integration(true);
ok(apply_filters('soft79_wcpr_product_pricing_enabled',true,wc_get_product($a))===false,'SOFT79 excluded for managed product');
ok(apply_filters('soft79_wcpr_product_pricing_enabled',true,wc_get_product($b))===false,'SOFT79 excluded for manual campaign product');
ok(apply_filters('soft79_wcpr_product_pricing_enabled',true,wc_get_product($c))===true,'SOFT79 remains for unowned product');
ok(apply_filters('soft79_wcpr_product_pricing_enabled',false,wc_get_product($c))===false,'preserves prior hook exclusions');
$engine->integration(false);
ok(apply_filters('soft79_wcpr_product_pricing_enabled',true,wc_get_product($a))===true,'integration switch can disable exclusions');
$engine->integration(true);
$engine->stop(); run_phase($engine,'stopped');
ok((float)wc_get_product($a)->get_regular_price('edit')===1200.0 && sale($a)===1080.0,'restores selected percentage and preserves changed regular');
ok(sale($b)===350.0 && (float)wc_get_product($b)->get_regular_price('edit')===550.0,'manual keep survives campaign end');
ok(wc_get_product($d)->get_sale_price('edit')==='','manual remove clears sale');
ok(SWS_Engine::same(SWS_Engine::snapshot(wc_get_product($e)),$dates),'restores original sale and timestamps exactly');
ok(apply_filters('soft79_wcpr_product_pricing_enabled',true,wc_get_product($a))===true,'SOFT79 released after restoration');
$finished=SWS_Store::campaign(); $original_journal=row($a);
$engine->save_settings(array('percent'=>17,'categories'=>array($child['term_id']),'soft79'=>1));
$saved=SWS_Store::campaign();
ok($saved['phase']==='stopped' && $saved['id']===$finished['id'] && row($a)===$original_journal,'saving after completion preserves the finished campaign journal');
ok($saved['soft79']===true,'SOFT79 preference can be saved without activation');
$engine->save_settings(array('percent'=>17));
ok(SWS_Store::campaign()['soft79']===false,'SOFT79 preference can be unchecked and saved');
// New campaign verifies preserved amount, manual override of a conflict and interrupted writes.
$engine->start(array('percent'=>25)); run_phase($engine,'active');
$p=wc_get_product($a); $p->set_regular_price(1400); $p->save(); $s=SWS_Store::campaign(); $engine->decide($a,'amount',row($a)['regular'],$s['id']);
$original=row($a)['original']['sale'];
$p=wc_get_product($e); $p->set_regular_price(1200); $p->save(); ok(row($e)['conflict'],'new scheduled-sale conflict');
$p=wc_get_product($e); $p->set_sale_price(850); $p->save(); ok(!row($e)['conflict'] && row($e)['status']==='manual','manual edit supersedes unresolved conflict');
$engine->stop(); run_phase($engine,'stopped'); ok(sale($a)===(float)$original,'amount choice restores exact original amount');
$interrupt=function($p) use($a) { if($p->get_id()===$a) throw new Exception('simulated interruption'); };
$engine->start(array('percent'=>40)); add_action('woocommerce_after_product_object_save',$interrupt,120);
try { $engine->process(); } catch(Throwable $error) {}
remove_action('woocommerce_after_product_object_save',$interrupt,120);
ok(row($a)['status']==='pending','interrupted engine write retains journal');
$baseline=row($a)['original']; run_phase($engine,'active'); ok(row($a)['status']==='managed','interrupted write resumes'); ok(row($a)['original']===$baseline,'retry does not replace original snapshot');
// Before-save failure must not be mistaken for an accepted manual change.
$interrupt_before=function($p) use($a) { if($p->get_id()===$a) throw new Exception('before-save failure'); };
add_action('woocommerce_before_product_object_save',$interrupt_before,120);
$p=wc_get_product($a); $p->set_sale_price(17); try{$p->save();}catch(Throwable $error){}
remove_action('woocommerce_before_product_object_save',$interrupt_before,120);
$engine->release_pending(); $engine->process(); ok(row($a)['status']==='managed','failed manual save rolls back journal intent');
$engine->stop(); add_action('woocommerce_after_product_object_save',$interrupt,120); try{$engine->process();}catch(Throwable $error){}
remove_action('woocommerce_after_product_object_save',$interrupt,120);
ok(row($a)['status']==='restoring','interrupted restoration journal retained');
run_phase($engine,'stopped'); ok(SWS_Engine::same(SWS_Engine::snapshot(wc_get_product($a)),$baseline),'interrupted restoration resumes without losing original');
// A new conflict while restoration is underway pauses it safely.
$engine->start(array('percent'=>50)); run_phase($engine,'active'); $engine->stop();
$p=wc_get_product($a); $p->set_regular_price(1600); $p->save(); $engine->process();
ok(SWS_Store::campaign()['phase']==='stopping' && row($a)['conflict'],'new conflict pauses in-progress restoration');
$s=SWS_Store::campaign(); $engine->decide($a,'percent',row($a)['regular'],$s['id']); run_phase($engine,'stopped');
ok((float)wc_get_product($a)->get_regular_price('edit')===1600.0,'late regular change retained after stopping');
// Invalid old amounts cannot silently disappear in WooCommerce validation.
$f=product('Lowered regular',1000,900);
$engine->start(array('percent'=>20)); run_phase($engine,'active');
$p=wc_get_product($f); $p->set_regular_price(500); $p->save(); $s=SWS_Store::campaign();
try{$engine->decide($f,'amount',row($f)['regular'],$s['id']);ok(false,'invalid restore amount rejected');}catch(RuntimeException $error){ok(true,'invalid restore amount rejected');}
$engine->decide($f,'percent',row($f)['regular'],$s['id']); $engine->stop(); run_phase($engine,'stopped'); ok(sale($f)===450.0,'lowered regular preserves original percentage');
// More than a batch, and stopping during activation restores only touched products.
for($i=0;$i<25;$i++) product('Batch '.$i,100);
$engine->start(array('percent'=>100)); $engine->process(); ok(SWS_Store::campaign()['phase']==='starting','activation uses multiple batches');
$active=SWS_Store::rows(SWS_Store::campaign(),'open',100); ok(count($active)>0 && count($active)<=20,'batch writes are bounded');
foreach($active as $entry) { if ((float) wc_get_product($entry['product_id'])->get_sale_price('edit') !== 0.0) throw new Exception('FAIL 100 percent zero sale'); }
ok(count($active)>0,'100 percent gives valid zero sale across the batch');
$engine->stop(); run_phase($engine,'stopped'); ok(SWS_Store::counts(SWS_Store::campaign())['done']===count($active),'activation can be cancelled with restoration');
// Deleting a conflicted product does not leave an impossible blocking decision.
$gone=product('Deleted conflict',1000,900); $engine->start(array('percent'=>20)); run_phase($engine,'active');
$p=wc_get_product($gone);$p->set_regular_price(1200);$p->save();ok(row($gone)['conflict'],'deleted fixture creates conflict');
wp_delete_post($gone,true);ok(row($gone)['status']==='done' && !row($gone)['conflict'],'deleted product clears obsolete conflict');
$engine->stop();run_phase($engine,'stopped');
$admin=null; foreach($GLOBALS['wp_filter']['admin_menu']->callbacks[10] as $hook) { if(is_array($hook['function']) && $hook['function'][0] instanceof SWS_Admin) $admin=$hook['function'][0]; }
ob_start();$admin->render();$html=ob_get_clean();
ok(strpos($html,'role="switch"')!==false && strpos($html,'categories[]')!==false && strpos($html,'products[]')!==false,'settings page renders switch and both selectors');
ok(strpos($html,'sws-layout')!==false && strpos($html,'sws-sidebar')!==false,'settings page has structured overview layout');
$found=$admin->search_results('categories','Child');ok(in_array($child['term_id'],array_column($found,'id'),true),'category search finds descendants');
ok(SWS_Admin::category_label(get_term($child['term_id'],'product_cat'))==='Excluded / Child','category labels include parent path');
$found=$admin->search_results('products','EXCLUDE-SINGLE');ok(in_array($single,array_column($found,'id'),true),'product search supports SKU');
$found=$admin->search_results('products','Private');ok(count($found)===0,'product search hides private products and their variations');
$found=$admin->search_results('products','External');ok(count($found)===0,'product search hides external products');
// Exact metadata restoration also covers dates attached to an empty original sale.
$dated=product('Dates without sale',1000);
$p=wc_get_product($dated);$p->set_date_on_sale_from('2030-01-01');$p->set_date_on_sale_to('2030-01-20');$p->save();
$empty_schedule=SWS_Engine::snapshot(wc_get_product($dated));
$engine->start(array('percent'=>20));run_phase($engine,'active');$engine->stop();run_phase($engine,'stopped');
ok(SWS_Engine::same(SWS_Engine::snapshot(wc_get_product($dated)),$empty_schedule),'empty original sale restores original date metadata');
// Concurrent commands must fail instead of overwriting one another.
$lease=SWS_Store::lock();
try { $engine->start(array('percent'=>20)); ok(false,'concurrent command rejected'); } catch(RuntimeException $error) { ok(true,'concurrent command rejected'); }
try { $engine->save_settings(array('percent'=>15)); ok(false,'concurrent settings save rejected'); } catch(RuntimeException $error) { ok(true,'concurrent settings save rejected'); }
SWS_Store::unlock($lease);
// Validate the access guard without allowing wp_die to exit the test process.
$die_filter=function(){ return function($message){ throw new RuntimeException('denied'); }; };
add_filter('wp_die_handler',$die_filter,PHP_INT_MAX);
wp_set_current_user(0); ok($admin->search_results('products','Excluded')===array(),'product search denies anonymous requests'); $access=new ReflectionMethod($admin,'access'); $access->setAccessible(true);
try { $access->invoke($admin); ok(false,'anonymous admin request rejected'); } catch(RuntimeException $error) { ok($error->getMessage()==='denied','anonymous admin request rejected'); }
wp_set_current_user(1);remove_filter('wp_die_handler',$die_filter,PHP_INT_MAX);
restore_error_handler();
echo 'SUCCESS '.$n." integration assertions\n";
