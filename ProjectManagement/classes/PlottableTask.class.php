<?php

class PlottableTaskTypes {
	const USER = 'U';
	const PROJECT = 'P';
	const CATEGORY = 'C';
	const BUG = 'B';
}

abstract class PlottableTask {
	protected $type;
	protected $id;
	protected $name;
	protected $weight;
	protected $due_date;

	public $children;

	protected $est;
	protected $done;
	protected $na;
	protected $overdue;
	protected $task_start;
	protected $task_end;
	protected $handler_id;

	private $calculated = false;

	public function __construct( $p_handler_id ) {
		$this->children = array();
		$this->est = 0;
		$this->done = 0;
		$this->overdue = 0;
		$this->handler_id = $p_handler_id;
	}

	public function plot( $p_last_dev_day, $p_min_date = null, $p_max_date = null ) {
		if ( is_null( $p_min_date ) ) {
			$p_min_date = $this->task_start;
		}
		if ( is_null( $p_max_date ) ) {
			$p_max_date = $this->task_end;
		}

		if ( $p_max_date - $p_min_date == 0 ) {
			return; # Prevent division by zero, don't plot this task
		}

		$t_unique_id = uniqid($this->type . '' . $this->id);

		$this->plot_specific_start( $t_unique_id, $p_last_dev_day, $p_min_date, $p_max_date );
		foreach ( $this->children as $child ) {
			$child->plot( $p_last_dev_day, $p_min_date, $p_max_date );
		}
		$this->plot_specific_end( $t_unique_id );
	}

	protected abstract function plot_specific_start( $p_unique_id, $p_last_dev_day, $p_min_date, $p_max_date );
	protected function plot_specific_end( $p_unique_id ) {
		# Standard behaviour does nothing
	}

	/***
	 * Returns an informational message based on this task's data,
	 * which can be used as tooltip. Time data of 8 hours or below are displayed
	 * as hours/minutes, above 8 hours are displayed in days.
	 * Can be overridden if desired.
	 * @return string the information message
	 */
	protected function generate_info_message() {
		$t_real_est = $this->est;
		if ( $this->na > 0 ) {
			$t_real_est -= $this->na;
		}

		$t_str = format_short_date( $this->task_start ) . ' - ' . format_short_date( $this->task_end ) . ' &nbsp; &#10;';
		if ( $t_real_est < 8 * 60 ) {
			$t_str .= plugin_lang_get( 'done' ) . ' (h): ' . minutes_to_time( $this->done, false )
				. ' / ' . minutes_to_time( $t_real_est, false );
			if ( $this->overdue > 0 ) {
				$t_str .= ' &nbsp; &#10;' . plugin_lang_get( 'overdue' ) . ' (h): ' . minutes_to_time( $this->overdue, false );
			}
		} else {
			$t_str .= plugin_lang_get( 'done' ) . ' (d): ' . minutes_to_days( $this->done )
				. ' / ' . minutes_to_days( $t_real_est );
			if ( $this->overdue > 0 ) {
				$t_str .= ' &nbsp; &#10;' . plugin_lang_get( 'overdue' ) . ' (d): ' . minutes_to_days( $this->overdue );
			}
		}

		if ( $this-> na > 0 ) {
			$t_str .= ' &nbsp; &#10;' . plugin_lang_get( 'unavailable' ) . ' (d): ' . minutes_to_days( $this->na );
		}

		return $t_str;
	}

	public function calculate_data( $p_reference_date ) {
		foreach ( $this->children as $child ) {
			$child->calculate_data( $p_reference_date );
		}
		if ( $this->calculated ) {
			return;
		}
		$this->calculate_data_specific( $p_reference_date );
		$this->calculated = true;
	}

	protected function calculate_data_specific( $p_reference_date ) {
		# Find the minimum start date and maximum end date of all children
		$t_min_start_date = 99999999999;
		$t_max_end_date = 1;
		foreach ( $this->children as $child ) {
			if ( $child->task_start < $t_min_start_date ) {
				$t_min_start_date = $child->task_start;
			}
			if ( $child->task_end > $t_max_end_date ) {
				$t_max_end_date = $child->task_end;
			}

			# The data of each task is the sum of the data of all its children
			$this->est += $child->est;
			$this->done += $child->done;
			$this->na += $child->na;
			$this->overdue += $child->overdue;
		}
		$this->task_start = $t_min_start_date;
		$this->task_end = $t_max_end_date;
	}

	public function remove_empty_children() {
		$t_has_children = false;
		foreach ( $this->children as $child ) {
			if ( count( $child->children ) > 0 ) {
				$t_has_children = true;
			}
		}
		if ( !$t_has_children ) {
			$this->children = array();
		}
	}

	protected function calculate_actual_end_date( $p_task_start, &$p_task_end, $p_todo_on_ref_date, &$p_est, &$p_na) {
		$p_task_end = $this->calculate_end_date( $p_task_start, $p_todo_on_ref_date );
		$p_total_na = 0;
		$p_new_na = $this->check_non_working_period( $p_task_start, $p_task_end );
		while ( $p_total_na != $p_new_na ) {
			$p_total_na = $p_new_na;
			$t_new_todo_on_ref_date = $p_todo_on_ref_date + $p_total_na;
			$p_task_end = $this->calculate_end_date( $p_task_start, $t_new_todo_on_ref_date );
			$p_new_na = $this->check_non_working_period( $p_task_start, $p_task_end );
		}
		$p_na = $p_total_na;
		$p_est += $p_total_na;
	}

	private function calculate_end_date( $p_task_start, $p_todo ) {
		global $g_resources;

		$t_task_end = $p_task_start;
		while ( $p_todo > 0 ) {
			$t_day_number = date( 'N', $p_task_start );
			$t_hours_for_day = $g_resources[$this->handler_id]['work_hours_per_day'][$t_day_number];
			if ( $t_hours_for_day == 0 ) {
				# Resource doesn't work this day - add a whole day
				$t_task_end += (24 * 60 * 60);
			} else {
				# Transform this amount of working hours to its 24-hour equivalent
				$t_relative_period = ((24 * 60 * 60) * min( $t_hours_for_day, $p_todo / 60 ) / $t_hours_for_day);
				$t_task_end += $t_relative_period;
			}

			# Move on to the next day
			$p_task_start += (24 * 60 * 60);
			$p_todo -= ($t_hours_for_day * 60);
		}

		return $t_task_end;
	}

	private function check_non_working_period( $p_task_start, $p_task_end ) {
		global $g_resources;

		$t_minutes_na = 0;
		$t_resource_unavailable = $g_resources[$this->handler_id]['resource_unavailable'];

		# Iterate through all the non-working days of the user
		if ( is_array( $t_resource_unavailable ) ) {
			foreach ( $t_resource_unavailable as $t_na_period ) {
				if ( $t_na_period['start_date'] <= $p_task_end && $t_na_period['start_date'] > $p_task_start ) {
					$t_day_to_check = $t_na_period['start_date'];
					while ( $t_day_to_check < $t_na_period['end_date'] ) {
						$t_day_number = date( 'N', $t_day_to_check );
						$t_hours_for_day = $g_resources[$this->handler_id]['work_hours_per_day'][$t_day_number];
						$t_minutes_na += $t_hours_for_day * 60;

						# Move on to the next day
						$t_day_to_check += (24 * 60 * 60);
					}
				}
			}
		}

		return $t_minutes_na;
	}
}