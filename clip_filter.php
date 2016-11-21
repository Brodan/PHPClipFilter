<?php
    /*
     * Copyright (C) 2016  Christopher Hranj
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <http://www.gnu.org/licenses/>.
     *
     * @file_name           vimeo.php
     * @author              Christopher Hranj <christopher.hranj@gmail.com>
     * @date_created        10/16/15
     * @date_last_modified  10/26/15
     * @php_version         5.5.9
     * @description         Analyze 'clips.csv' and output to 'valid.csv' and 'invalid.csv'.
     */

    /* A Clip object stores various information about a video
     * clip and contains functionality for returning and outputting
     * data about the clip.
     */ 
    class Clip {
        private $id;
        private $title;
        private $privacy;
        private $total_plays;
        private $total_comments;
        private $total_likes;

        /* Unpack $clip_data array as input and assign
         * to appropriate instance variables.
         */
        public function __construct($clip_data) {
            $this->id = (string) $clip_data[0];
            $this->title = (string) $clip_data[1];
            $this->privacy = (string) $clip_data[2];
            $this->total_plays = (int) $clip_data[3];
            $this->total_comments = (int) $clip_data[4];
            $this->total_likes = (int) $clip_data[5];
        }

        /* Return all instance variables as an array.
         * This approach was chosen so that only one method
         * call is needed when filtering Clip objects with the
         * CustomFilter, and to avoid the need for six different
         * getter methods.
         */
        public function get_values() {
            return array('id' => $this->id,
                         'title' => $this->title,
                         'privacy' => $this->privacy,
                         'total_plays' => $this->total_plays,
                         'total_comments' => $this->total_comments,
                         'total_likes' => $this->total_likes
                        );
        }

        public function __toString() {
            return $this->id;
        }
    }

    /* CustomFilter extends the SPL FilterIterator class. It allows for 
     * filtering of a given Iterator based on a given list of filters.
     *
     * The accept() function is overridden to handle iterating through a
     * given Iterator and determining if the items in the Iterator are valid
     * or invalid.
     *  
     * The open_output() and close_output() methods must be used to ensure
     * files are opened and closed for writing appropriately. These methods
     * are included to allow the CustomFilter to re-open files for additional writing
     * if necessary. 
     */
    class CustomFilter extends FilterIterator {
        private $filter_rules;
        private $filter_keys;
        private $valid_filename;
        private $invalid_filename;
        private $valid_file;
        private $invalid_file;

        public function __construct(Iterator $iterator, $filter_rules, $valid_filename, $invalid_filename) {
            parent::__construct($iterator);
            $this->filter_rules = $filter_rules;
            $this->filter_keys = array_keys($filter_rules);
            $this->valid_filename = $valid_filename;
            $this->invalid_filename = $invalid_filename;
        }

        /* accept() runs every item through every filter in the $filter_rules array.
         * 
         * Rather than return True or False, the accept() function writes items to
         * the appropriate file. This ensures that items that would be filtered
         * out are still be written to the $invalid_file.
         */
        public function accept() {
            $item = $this->current();

            $data_for_filter = [];
            $item_data = $item->get_values();
                
            /* Use each array key in $fiter_rules to determine the key of the
             *item_data that needs to be filtered.
             */
            foreach($this->filter_keys as $key) {
                $data_for_filter[$key] = $item_data[$key];
            }
            $filter_results = filter_var_array($data_for_filter, $this->filter_rules);

            /* Check if item passed all filters with strict type checking set to true.
             * If a false value is in $filter_results then it did not pass all filters.
             */
            if (in_array(false, $filter_results, true)) {
                # Write to invalid output file using item's __toString() method.
                fputcsv($this->invalid_file, [(string) $item]);
            }
            else {
                # Write to valid output file using item's __toString() method.
                fputcsv($this->valid_file, [(string) $item]);
            }
        }

        public function open_output() {
            $this->valid_file = fopen($this->valid_filename, 'w');
            $this->invalid_file = fopen($this->invalid_filename, 'w');
        }

        public function close_output() {
            fclose($this->valid_file);
            fclose($this->invalid_file);
        }
    }

    /* The ClipHandler is designed to handle Clip objects and allow them
     * to interact with the CustomFilter. 
     *
     * To specify the constraints for a valid/invalid Clip, 
     * the $filters instance variable should be modified.
     *
     * The ClipHandler includes functionality to build an Interator of Clips
     * and pass it to the CustomFilter for filtering. 
     */
    class ClipHandler {
        
        private $input_file;
        private $valid_output;
        private $invalid_output;
        private $clipIterator;

        /* List of filters that will be passed to the CustomFiler.
         * Only filters native to PHP are used for modularity. See:
         * http://php.net/manual/en/filter.filters.php 
         * for filter types.
         */
        private $filters = array(
            'total_plays'  => array('filter'    => FILTER_VALIDATE_INT,
                                    'options'   => array('min_range' => 201)
                                   ),
            'total_likes'  => array('filter'    => FILTER_VALIDATE_INT,
                                    'options'   => array('min_range' => 11)
                                   ),
            'title'        => array('filter'    => FILTER_VALIDATE_REGEXP,
                                    'options'   => array('regexp'=>'/^.{0,29}$/')
                                   ),
            'privacy'      => array('filter'    => FILTER_VALIDATE_REGEXP,
                                    'options'   => array('regexp'=>'/anybody/')
                                   )
        );

        public function __construct($input_filename, $valid_output, $invalid_output) {
            $this->input_file = $input_filename;
            $this->valid_output = $valid_output;
            $this->invalid_output = $invalid_output;
        }

        public function build_clips() {
            /* Read in the given input file as an array, and remove the first element,
             * which contains the clip's column names.
             */
            $clips = array_slice(file($this->input_file), 1);
            # Instantiate a new array to hold our Clip objects.
            $clip_list = new ArrayObject;

            foreach ($clips as $clip) {
                $new_clip = str_getcsv($clip);
                
                /* Ideally, arugment unpacking would be used here via the ...
                 * operator, but that feature was not introduced until PHP 5.6.
                 * 
                 * As a result, an array is being passed to create the new Clip
                 * object and the arguement unpacking is handled in the Clip's
                 * constructor.
                 */
                $clip_list[] = new Clip(array_values($new_clip));
            }

            /* Instantiate the CustomFilter object using the $clip_list 
             * built above and the given input and output files.
             */
            $this->clipIterator = new CustomFilter(
                $clip_list->getIterator(),
                $this->filters,
                $this->valid_output,
                $this->invalid_output
            );
        }

        public function write_output() {
            # Open output files before iterating through CustomFilter.
            $this->clipIterator->open_output();

            /* Iterate through the FilterIterator and do nothing.
             * The loop is empty because writing to output files is handled
             * within the Clip_Filter class.
             */
            foreach ($this->clipIterator as $clip) {
            }

            # Close open output files.
            $this->clipIterator->close_output();
        }
    }

    /* Ensure that errors opening a file are handled and not just displayed
     * as a warning. This is necessary because file writing errors are
     * treated as wanings and can not be caught using a try/catch block.
     *
     * In the event a warning is produced, the program will exit gracefully
     * and display the appropriate error message.
     */ 
    function warning_handler($errno, $errstr) {
        exit("Error: " . $errstr . "\nExiting...\n");
    }
    set_error_handler("warning_handler", E_WARNING);

    # Build a new ClipHandler, specifying the input and output files.
    $clip_handler = new ClipHandler('clips.csv', 'valid.csv', 'invalid.csv');
    # Build list of Clip objects for future processing.
    $clip_handler->build_clips();
    # Filter list of Clip objects and write results to appropriate outpu files.
    $clip_handler->write_output();

    # Set the error handler back to default settings.
    restore_error_handler();
?>