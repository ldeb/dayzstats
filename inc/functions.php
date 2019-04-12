<?php
// NOTE:

////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// PARSE LOGFILE
////////////////////////////////////////////////////////////////////////////////////////////////////////////////

function invert_victim($matches) {
  $victim['name'] = $matches['user1_name'];
  $victim['id'] = $matches['user1_id'];
  $victim['pos'] = $matches['user1_pos'];
  if( isset($matches['user2_name']) && isset($matches['user2_id']) && isset($matches['user2_pos']) ) {
    $matches['user1_name'] = $matches['user2_name'];
    $matches['user1_id'] = $matches['user2_id'];
    $matches['user1_pos'] = $matches['user2_pos'];
  } else {
    unset($matches['user1_name']);
    unset($matches['user1_id']);
    unset($matches['user1_pos']);
  }
  $matches['user2_name'] = $victim['name'];
  $matches['user2_id'] = $victim['id'];
  $matches['user2_pos'] = $victim['pos'];
  unset($victim);

  return $matches;
}

function set_time($death_time, $current_datetime) {  // the time (00:01:00) can change in the middle a log file
  $death_datetime = new DateTime( $current_datetime->format('Y-m-d').' '.$death_time);
  if( $death_datetime->format('H:i:s') < $current_datetime->format('H:i:s') ) { // next day
    $death_datetime->add(new DateInterval('P1D'));
  }
  return $death_datetime->format('Y-m-d H:i:s');
}

function parse_log($CONFIG) {
  $filename = $CONFIG['logfile'];
  $pattern_time = '(?\'time\'\d{2}:\d{2}:\d{2})';
  $pattern_user_id_pos = '(?\'user1_name\'.+)\s\(steam64id=(?\'user1_id\'.+)\spos=<(?\'user1_pos\'.+)>\)';
  $pattern_user_id_pos2 = '(?\'user2_name\'.+)\s\(steam64id=(?\'user2_id\'.+)\spos=<(?\'user2_pos\'.+)>\)';

  $handle = @fopen($filename, 'r');
  $results = array();
  $i = 0;
  $skipped = array();
  if ($handle) {
    while (($line = fgets($handle)) !== false) {
      $i++;
      if( $i == 1 ) { // first line
        preg_match('/^Log\sCreated\son\s(\d{4}-\d{2}-\d{2})\sat\s(\d{2}:\d{2}:\d{2})/', $line, $matches); // logfile datetime
        $log_date = new DateTime($matches[1].'T'.$matches[2].'Z');
        echo '<strong>Log file date: '.$log_date->format('Y-m-d H:i:s').'</strong><br />';

      } else {
        /////////////////////
        // Kills
        /////////////////////
        // time | killer killed victim (with/while driving) ...
        if( preg_match('/^'.$pattern_time.'\s\|\s'.$pattern_user_id_pos.'\skilled+\s'.$pattern_user_id_pos2.'\s(?>with|while\sdriving)+\s(?\'line_end\'.+)\./', $line, $matches) == 1 ) {
          if( preg_match('/^(?\'reason\'.+)\s\[(?\'dist\'\d+)m\]/', $matches['line_end'], $matches2) == 1 ) {           // reason [distm]
            $matches['reason'] = $matches2['reason'];                               // reason (weapon)
            if( isset($matches2['dist']) ) $matches['dist'] = $matches2['dist'];    // distance
            unset($matches['line_end']);
          } else {
            if( isset($matches['line_end']) ) {
              $matches['reason'] = $matches['line_end'];                            // reason (weapon)
              unset($matches['line_end']);
            } else {
              echo 'error1';
            }
          }
        }
        /////////////////////
        // Kills (bled out from)
        /////////////////////
        // time | victim bled out from killer's reason
        else if( preg_match('/^'.$pattern_time.'\s\|\s'.$pattern_user_id_pos.'\sbled\sout\sfrom+\s'.$pattern_user_id_pos2.'\'s\s(?\'reason\'.+)\./', $line, $matches) == 1 ) {
          $matches = invert_victim($matches); // invert victim/killer
        }
        /////////////////////
        // Death only
        /////////////////////
        // time | victim (died due to/died to/bled out from cuts by/died/woke with open wounds and) reason
        else if( preg_match('/^'.$pattern_time.'\s\|\s'.$pattern_user_id_pos.'\s(?>died\sdue\sto|died\sto|bled\sout\sfrom\scuts\sby|died|woke\swith\sopen\swounds\sand)+\s(?\'reason\'.+)\./', $line, $matches) == 1 ) {
          $matches = invert_victim($matches); // invert victim/killer
        }
        /////////////////////
        // parse failed
        /////////////////////
        else {
          $skipped[] = $line;
        }

        // clean array, only keep string keys
        foreach ($matches as $key => $value) if( is_int($key) ) unset($matches[$key]);

        /////////////////////
        // Commun operations
        /////////////////////
        if( isset($matches['time']) )  $matches['time'] = set_time($matches['time'], $log_date);  // update datetime
        if( isset($matches['user1_name']) )  $matches['user1_name'] = iconv("Windows-1251", "UTF-8//TRANSLIT", $matches['user1_name']); // convert username charset
        if( isset($matches['user2_name']) )  $matches['user2_name'] = iconv("Windows-1251", "UTF-8//TRANSLIT", $matches['user2_name']);

        // var_dump($matches);

        // 1 => string '21:33:10' (length=8)
        // 2 => string 'STUPEAD' (length=7)
        // 3 => string '76 561 198 050 277 984' (length=17)
        // 4 => string '1648.1, 3593.0, 133.2' (length=21)
        // 5 => string 'Grego' (length=5)
        // 6 => string '76561198422180913' (length=17)
        // 7 => string '1675.1, 3597.0, 133.6' (length=21)
        // 8 => string 'SK 59/66' (length=8)
        // 9 => string '27' (length=2)

        // add to results
        $results[] = $matches;
      }
    } // end while
    fclose($handle);

    // var_dump($results);
    // generate_table($results);

    // DEBUG: Parse errors
    if( $CONFIG['DEBUG'] ){
      echo '<strong>'. count($skipped) .' parsing deaths missed!</strong>';
      if( count($skipped) > 0 ) var_dump($skipped);
    }

  } else {
    echo '<span class="text-danger">Error opening file <strong>'.$filename.'</strong></span>';
  }
  return $results;
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// DATATABLE
////////////////////////////////////////////////////////////////////////////////////////////////////////////////

function generete_user_link($label, $user_steamid) {
  $link = ( is_numeric($user_steamid) ) ? '<a href="https://steamcommunity.com/profiles/'.$user_steamid.'" target="_blank">'.$label.'</a>' : '';
  return $link;
}

function generate_table($results) {
  $nc_char = '-';
  ?>
  <table class="datatable table table-striped table-sm table-bordered">
    <thead>
      <tr>
        <th>date</th>
        <th>killer</th>
        <th>victim</th>
        <th>weapon</th>
        <th>distance</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($results as $i => $action): ?>
        <tr>
          <td><?= isset($action['time']) ? $action['time'] : $nc_char; ?></td>
          <td><?= isset($action['user1_name']) ? $action['user1_name'].' '.generete_user_link('+', $action['user1_id']) : $nc_char; ?></td>
          <td><?= isset($action['user2_name']) ? $action['user2_name'].' '.generete_user_link('+', $action['user2_id']) : $nc_char; ?></td>
          <td><?= isset($action['reason']) ? $action['reason'] : $nc_char; ?></td>
          <td><?= isset($action['dist']) ? $action['dist'] : $nc_char; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// SHOW DEATHS ON MAP
////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// $worldspace = '2167.5, 4369.4, 209.4'
function coord2px($worldspace){
	$y_diff = 15360;
	$coords = explode(', ', $worldspace);
	if( count($coords)==3 ) {
		$result = array();
		$result[0] = floatval( $coords[0] ) / 10;
		$result[1] = ($y_diff - floatval( $coords[1] )) / 10;
		$result[2] = floatval( $coords[2] );
		return $result;
	} else {
		return array(0,0,0);
	}
}

function show_player_on_map($player_name, $player_id, $player_pos, $legend, $is_a_killer) {
  $coef = 1;
  $coords = coord2px($player_pos);
  $class = $is_a_killer ? ' killer' : '';
  echo '<div class="elem'.$class.'" title="'.$legend.'" data-toggle="tooltip0" data-trigger="click" style="left:'.($coords[0] * $coef).'px; top:'.($coords[1] * $coef).'px;">';
    echo '<div class="point"></div>';
  echo '</div>';
}

function show_deaths_on_map($CONFIG, $results) {
	foreach($results as $action){

    $killerInvolve = isset($action['user1_name']);
    $legend = '';

    if( $CONFIG['show_death_details_on_map'] ) {
      $legend = $killerInvolve ? $action['user2_name']. ' killed by '. $action['user1_name'] : $action['user2_name'].' died';
      $legend.=' ('.$action['reason'].')';
      if( isset($action['dist']) ) $legend.= ' ['.$action['dist'].'m]';
      // else if($killerInvolve) $legend.= ' [bled out]';  // bled out
    }
    show_player_on_map($action['user2_name'], $action['user2_id'], $action['user2_pos'], $legend, false);

    if( $killerInvolve ) {  // there is a killer involve
      if( $CONFIG['show_death_details_on_map'] ) {
        $legend = $action['user1_name']. ' killed '. $action['user2_name'];
        $legend.=' ('.$action['reason'].')';
        if( isset($action['dist']) ) $legend.= ' ['.$action['dist'].'m]';
        // else $legend.= ' [bled out]';  // bled out
      }
      show_player_on_map($action['user1_name'], $action['user1_id'], $action['user1_pos'], $legend, true);
    }
	}
}
