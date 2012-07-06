<?php

class MantisPmBug {
	public $bug_id;
	public $handler_id;
	public $est;
	public $done;
	public $todo;

	private $bug_data;

	public function __construct( $p_bug_id ) {
		$this->bug_id   = $p_bug_id;
		$this->est      = array();
		$this->done     = array();
		$this->todo     = array();
		$this->bug_data = null;
	}

	public function calculate_bug_data() {
		if ( !is_null( $this->bug_data ) ) {
			# Only calculate once
			return;
		}

		$t_worktypes = MantisEnum::getAssocArrayIndexedByValues( plugin_config_get( 'work_types' ) );

		foreach ( $this->done as $t_value ) {
			@$this->bug_data[PLUGIN_PM_DONE] += $t_value;
		}

		$t_todo = 0;
		foreach ( $t_worktypes as $t_work_type => $t_value ) {
			if ( isset( $this->todo[$t_work_type] ) ) {
				$t_todo += $this->todo[$t_work_type];
			} else if ( isset( $this->est[$t_work_type] ) ) {
				$t_todo += max( $this->est[$t_work_type] - @$this->done[$t_work_type], 0 );
			}
		}
		$this->bug_data[PLUGIN_PM_TODO] = max( $t_todo, 0 );

		# Calculate the 'real estimate'
		$this->bug_data[PLUGIN_PM_EST] = @$this->bug_data[PLUGIN_PM_DONE] + @$this->bug_data[PLUGIN_PM_TODO];

		$t_overdue = 0;
		foreach ( $t_worktypes as $t_work_type => $t_value ) {
			$t_overdue += @$this->done[$t_work_type] + @$this->todo[$t_work_type] - @$this->est[$t_work_type];
		}
		$this->bug_data[PLUGIN_PM_OVERDUE] = max( $t_overdue, 0 );
	}

	public function add_bug_data( &$p_data ) {
		$this->calculate_bug_data();

		foreach ( $this->bug_data as $t_minutse_type => $t_value ) {
			@$p_data[$this->handler_id][$t_minutse_type] += $t_value;
		}
	}

	public function print_bug( $p_total_value = -1 ) {
		echo '<div class="bug">';
		$t_alternate_class = helper_alternate_class();

		echo '<span class="progress-total-section">';

		echo '<span ' . $t_alternate_class . '>';
		echo '<span class="progress-text-section">', string_get_bug_view_link( $this->bug_id, null, plugin_config_get( 'display_detailed_bug_link' ) ), '</span>';
		echo '</span>';

		$this->calculate_bug_data();

		$t_est     = @$this->bug_data[PLUGIN_PM_EST];
		$t_done    = @$this->bug_data[PLUGIN_PM_DONE];
		$t_overdue = @$this->bug_data[PLUGIN_PM_OVERDUE];

		# Calculate the width of the bug
		$t_total_width = $t_est / $p_total_value * 100;

		if ( $t_est > 0 ) {
			$t_original_work_width = ( $t_done - $t_overdue ) / $t_est * 100;
			$t_total_work_width    = $t_done / $t_est * 100;
			$t_extra_work_width    = $t_overdue / $t_est * 100;
		} else {
			$t_original_work_width = 0;
			$t_total_work_width    = 0;
			$t_extra_work_width    = 0;
		}

		$t_progress_info = minutes_to_time( $t_done, false ) . '&nbsp;/&nbsp;' . minutes_to_time( $t_est, false );
		$t_progress_text = '<a href="#" class="invisible" title="' . $t_progress_info . '">' . number_format( $t_total_work_width, 1 ) . '%</a>';
		$t_overdue_text = '<a href="#" title="' . minutes_to_time( $t_overdue ) . '&nbsp;/&nbsp;' . minutes_to_time( $t_done ) . '"></a>';

		echo '<span class="progress-bar-section">';

		echo '<div class="resource-section">';
		echo '<span ' . $t_alternate_class . '>';
		echo '<span class="resource-name-section">' . prepare_resource_name( $this->handler_id ) . '</span>';
		echo '</span>';

		echo '<span class="resource-progress-section">';
		print_progress_span( $this->handler_id, $t_total_width );
		print_progressbar_span( $this->handler_id, $t_original_work_width );
		echo $t_progress_text . '</span>';
		if ( $t_extra_work_width > 0 ) {
			print_overdue_span( $t_extra_work_width );
			echo $t_overdue_text . '</a></span>';
		}
		echo '</span>';
		echo '</span>'; # End of resource progress section

		echo '</div>'; # End of resource section

		echo '</span>'; # End of bar section

		echo '</span>'; # End of total section

		echo '</div>';
	}
}
