<?php

namespace App\Libraries;

use App\Models\Api\EventCategoryModel;

class CategoryLibrary
{
    // Function to generate Invite Reference Code
    public function count($entry_type_id)
    {
        $invite_total = 0;
        $male_total = 0;
        $female_total = 0;
        $other_total = 0;
        $couple_total = 0;
        $entryTypeValue = null;
        $entryTypeText = null;

        switch ($entry_type_id) {

            case '1':
                // For Male
                $entryTypeValue = 1;
                $entryTypeText = 'Male';
                $invite_total = 1;
                $male_total = 1;
                break;

            case '2':
                // Female
                $entryTypeValue = 2;
                $entryTypeText = 'Female';
                $invite_total = 1;
                $female_total = 1;
                break;

            case '3':
                // Others
                $entryTypeValue = 3;
                $entryTypeText = 'Other';
                $invite_total = 1;
                $other_total = 1;
                break;

            case '4':
                // Couple
                $entryTypeValue = 4;
                $entryTypeText = 'Couple';
                $invite_total = 2;
                $couple_total = 1;
                break;


            default:
                return false;
        }

        return array(
                        'entryTypeValue' => $entryTypeValue,
                        'entryTypeText' => $entryTypeText,
                        'invite_total' => $invite_total,
                        'male_total' => $male_total,
                        'female_total' => $female_total,
                        'other_total' => $other_total,
                        'couple_total' => $couple_total,
                    );
    }

    // Function to generate Invite Reference Code
    public function categoryCount($entry_type_id, $partner = null)
    {
        $invite_total = 0;
        $male_total = 0;
        $female_total = 0;
        $other_total = 0;
        $couple_total = 0;
        $entryTypeValue = null;
        $entryTypeText = null;

        switch ($entry_type_id) {

            case '1':
                // For Male
                $entryTypeValue = 1;
                $entryTypeText = 'Male';
                $invite_total = 1;
                $male_total = 1;
                break;

            case '2':
                // Female
                $entryTypeValue = 2;
                $entryTypeText = 'Female';
                $invite_total = 1;
                $female_total = 1;
                break;

            case '3':
                // Others
                $entryTypeValue = 3;
                $entryTypeText = 'Other';
                $invite_total = 1;
                $other_total = 1;
                break;

            case '4':
                // Couple
                if ($partner == '') {
                    return array('success' => 0, 'message' => 'Partner is required for couple entry.'); 
                }

                $entryTypeValue = 4;
                $entryTypeText = 'Couple';
                $invite_total = 2;
                $couple_total = 1;
                break;


            default:
                return false;
        }

        return array(
                        'entryTypeValue' => $entryTypeValue,
                        'entryTypeText' => $entryTypeText,
                        'invite_total' => $invite_total,
                        'male_total' => $male_total,
                        'female_total' => $female_total,
                        'other_total' => $other_total,
                        'couple_total' => $couple_total,
                    );
    }
}