<?php
/**
 * Standalone harness for includes/schema.php — no WordPress required.
 * Run: php test-schema.php
 */
define( 'ABSPATH', __DIR__ );

$GLOBALS['opts'] = array();
$GLOBALS['meta'] = array();

function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][$k] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][$k] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_post_type( $id ) { return $GLOBALS['meta'][$id]['__type'] ?? 'pet-gallery'; }
function update_meta_cache() {}
function wp_schedule_single_event() {}
class WP_Post {}

require __DIR__ . '/../includes/schema.php';

$pass = 0; $fail = 0;
function is_same( $label, $got, $want ) {
    global $pass, $fail;
    $ok = ( $got === $want );
    $ok ? $pass++ : $fail++;
    printf( "%s %-58s got %-26s want %s\n", $ok ? ' ok ' : 'FAIL',
        $label, var_export( $got, true ), var_export( $want, true ) );
}

echo "--- classify: petkey codes (animal_type) ---\n";
is_same( "'DOG'",   wcpk_gallery_classify_type('DOG'),   'DOG' );
is_same( "'CAT'",   wcpk_gallery_classify_type('CAT'),   'CAT' );
is_same( "'BIRD'",  wcpk_gallery_classify_type('BIRD'),  'BIRD' );
is_same( "'OTHER'", wcpk_gallery_classify_type('OTHER'), 'OTHER' );
is_same( "'REPTILE'", wcpk_gallery_classify_type('REPTILE'), 'REPTILE' );

echo "\n--- classify: UKM pet_type term NAMES (_pet_type) ---\n";
foreach ( array('Dog'=>'DOG','Cat'=>'CAT','Bird'=>'BIRD') as $in=>$want )
    is_same( "'$in'", wcpk_gallery_classify_type($in), $want );
echo "  (these are the ones the old filter silently dropped:)\n";
foreach ( array('Rabbit','Guinea Pig','Hamster','Ferret','Chinchilla','Hedgehog') as $in )
    is_same( "'$in'", wcpk_gallery_classify_type($in), 'OTHER' );
foreach ( array('Bearded Dragon'=>'REPTILE','Ball Python'=>'REPTILE','Leopard Gecko'=>'REPTILE','Snake'=>'REPTILE') as $in=>$want )
    is_same( "'$in'", wcpk_gallery_classify_type($in), $want );

echo "\n--- classify: empty must stay '' (never stamped, never excluded) ---\n";
is_same( "''",     wcpk_gallery_classify_type(''),     '' );
is_same( "'   '",  wcpk_gallery_classify_type('   '),  '' );
is_same( 'null',   wcpk_gallery_classify_type(null),   '' );

echo "\n--- classify: case / whitespace robustness ---\n";
is_same( "'  dogs  '",   wcpk_gallery_classify_type('  dogs  '),  'DOG' );
is_same( "'Small Animals'", wcpk_gallery_type_token_to_code('Small Animals'), 'OTHER' );
is_same( "'Puppies'",    wcpk_gallery_classify_type('Puppies'),   'DOG' );

echo "\n--- allowed codes: the three historical option shapes ---\n";
$GLOBALS['opts']['petkey_gallery_types'] = array();
is_same( 'empty option => allow all', wcpk_gallery_get_allowed_type_codes(), array() );

// Shape (b): THE BUG — nothing ticked wrote CODE => LABEL and blanked the grid.
$GLOBALS['opts']['petkey_gallery_types'] = array('DOG'=>'Dogs','CAT'=>'Cats','BIRD'=>'Birds','REPTILE'=>'Reptiles','OTHER'=>'Small Animals');
is_same( 'all-labels (the bug) => allow all', wcpk_gallery_get_allowed_type_codes(), array() );

// Shape (a): a genuine partial save.
$GLOBALS['opts']['petkey_gallery_types'] = array('DOG'=>'DOG','CAT'=>'CAT');
is_same( 'codes DOG+CAT', wcpk_gallery_get_allowed_type_codes(), array('DOG','CAT') );

// Shape (c): the new flat list.
$GLOBALS['opts']['petkey_gallery_types'] = array('DOG','BIRD');
is_same( 'flat list DOG+BIRD', wcpk_gallery_get_allowed_type_codes(), array('DOG','BIRD') );

// A partial save using labels.
$GLOBALS['opts']['petkey_gallery_types'] = array('DOG'=>'Dogs');
is_same( 'partial labels => DOG', wcpk_gallery_get_allowed_type_codes(), array('DOG') );

echo "\n--- query clause ---\n";
$GLOBALS['opts']['petkey_gallery_types'] = array();
is_same( 'allow-all => no clause', wcpk_gallery_allowed_types_clause(), null );

$GLOBALS['opts']['petkey_gallery_types'] = array('DOG'=>'DOG');
$c = wcpk_gallery_allowed_types_clause();
is_same( 'clause relation', $c['relation'] ?? null, 'OR' );
is_same( 'clause IN values', $c[0]['value'] ?? null, array('DOG') );
is_same( 'clause fails OPEN (NOT EXISTS leg)', $c[1]['compare'] ?? null, 'NOT EXISTS' );

echo "\n--- stamping ---\n";
$GLOBALS['meta'][101] = array('animal_type'=>'DOG');
is_same( 'petkey post stamps DOG', wcpk_gallery_stamp_type_code(101), 'DOG' );
$GLOBALS['meta'][102] = array('_pet_type'=>'Guinea Pig');
is_same( 'ukm small animal stamps OTHER', wcpk_gallery_stamp_type_code(102), 'OTHER' );
$GLOBALS['meta'][103] = array();
is_same( 'no type info => not stamped', wcpk_gallery_stamp_type_code(103), '' );
is_same( '  and no meta row written', isset($GLOBALS['meta'][103][WCPK_GALLERY_TYPE_META]), false );

// OTHER must be recomputed once the alias is filled in later.
$GLOBALS['meta'][104] = array( WCPK_GALLERY_TYPE_META => 'OTHER' );
$GLOBALS['meta'][104]['_pet_type'] = 'Dog';
is_same( 'provisional OTHER recomputed to DOG', wcpk_gallery_stamp_type_code(104), 'DOG' );

// A settled code is not recomputed without force.
$GLOBALS['meta'][105] = array( WCPK_GALLERY_TYPE_META => 'CAT', 'animal_type' => 'DOG' );
is_same( 'settled code left alone', wcpk_gallery_stamp_type_code(105), 'CAT' );
is_same( '  force recomputes it',   wcpk_gallery_stamp_type_code(105, true), 'DOG' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
