<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;
use Gibbon\Module\ATL\Domain\ATLColumnGateway;
use Gibbon\Services\Format;

//Module includes
require_once __DIR__ . '/moduleFunctions.php';

echo "<script type='text/javascript'>";
    echo '$(document).ready(function(){';
        echo "autosize($('textarea'));";
    echo '});';
echo '</script>';

if (isActionAccessible($guid, $connection2, '/modules/ATL/atl_write_data.php') == false) {
    //Acess denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Register scripts available to the core, but not included by default
    $page->scripts->add('chart');

    $highestAction = getHighestGroupedAction($guid, $_GET['q'], $connection2);
    if ($highestAction == false) {
        $page->addError(__('The highest grouped action cannot be determined.'));
    } else {
        //Check if school year specified
        $gibbonCourseClassID = $_GET['gibbonCourseClassID'] ?? '';
        $atlColumnID = $_GET['atlColumnID'] ?? '';
        if ($gibbonCourseClassID == '' || $atlColumnID == '') {
            $page->addError(__('You have not specified one or more required parameters.'));
        } else {
            try {
                if ($highestAction == 'Write ATLs_all') {
                    $data = array('gibbonCourseClassID' => $gibbonCourseClassID);
                    $sql = "SELECT gibbonCourse.nameShort AS course, gibbonCourse.name AS courseName, gibbonCourseClass.nameShort AS class, gibbonYearGroupIDList FROM gibbonCourse JOIN gibbonCourseClass ON (gibbonCourse.gibbonCourseID=gibbonCourseClass.gibbonCourseID) WHERE gibbonCourseClassID=:gibbonCourseClassID AND gibbonCourseClass.reportable='Y' ";
                } else {
                    $data = array('gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonPersonID' => $session->get('gibbonPersonID'), 'gibbonCourseClassID2' => $gibbonCourseClassID, 'gibbonPersonID2' => $session->get('gibbonPersonID'), 'gibbonSchoolYearID' => $session->get('gibbonSchoolYearID'));
                    $sql = "(SELECT gibbonCourse.nameShort AS course, gibbonCourse.name AS courseName, gibbonCourseClass.nameShort AS class, gibbonYearGroupIDList FROM gibbonCourse JOIN gibbonCourseClass ON (gibbonCourse.gibbonCourseID=gibbonCourseClass.gibbonCourseID) JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID=gibbonCourseClass.gibbonCourseClassID) WHERE gibbonCourseClass.gibbonCourseClassID=:gibbonCourseClassID AND gibbonPersonID=:gibbonPersonID AND (role='Teacher' OR role='Assistant') AND gibbonCourseClass.reportable='Y')
                        UNION
                        (SELECT gibbonCourse.nameShort AS course, gibbonCourse.name AS courseName, gibbonCourseClass.nameShort AS class, gibbonYearGroupIDList FROM gibbonCourseClass JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID) JOIN gibbonDepartment ON (gibbonCourse.gibbonDepartmentID=gibbonDepartment.gibbonDepartmentID) JOIN gibbonDepartmentStaff ON (gibbonDepartmentStaff.gibbonDepartmentID=gibbonDepartment.gibbonDepartmentID) WHERE gibbonCourseClass.gibbonCourseClassID=:gibbonCourseClassID2 AND gibbonDepartmentStaff.gibbonPersonID=:gibbonPersonID2 AND gibbonDepartmentStaff.role='Coordinator' AND gibbonCourse.gibbonSchoolYearID=:gibbonSchoolYearID AND gibbonCourseClass.reportable='Y')";
                }
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException $e) {
            }

            if ($result->rowCount() != 1) {
                $page->addError(__('The selected record does not exist, or you do not have access to it.'));
            } else {
                $atlColumnGateway = $container->get(ATLColumnGateway::class);
                $atlColumn = $atlColumnGateway->getByID($atlColumnID);

                if (empty($atlColumn)) {
                    $page->addError(__('The selected column does not exist, or you do not have access to it.'));
                } else {
                    //Let's go!
                    $class = $result->fetch();

                    $page->breadcrumbs
                      ->add(__('Write {courseClass} ATLs', ['courseClass' => $class['course'].'.'.$class['class']]), 'atl_write.php', ['gibbonCourseClassID' => $gibbonCourseClassID])
                      ->add(__('Enter ATL Results'));

                    if ($atlColumn['forStudents'] == 'Y') {
                        $page->addError(__('You cannot mark this ATL'));
                    } else {
                        $data = array('gibbonCourseClassID' => $gibbonCourseClassID, 'atlColumnID' => $atlColumnID, 'today' => date('Y-m-d'));
                        $sql = "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.surname, gibbonPerson.preferredName, gibbonPerson.dateStart, atlEntry.*
                            FROM gibbonCourseClassPerson
                            JOIN gibbonPerson ON (gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID)
                            LEFT JOIN atlEntry ON (atlEntry.gibbonPersonIDStudent=gibbonPerson.gibbonPersonID AND atlEntry.atlColumnID=:atlColumnID)
                            WHERE gibbonCourseClassPerson.gibbonCourseClassID=:gibbonCourseClassID
                            AND gibbonCourseClassPerson.reportable='Y' AND gibbonCourseClassPerson.role='Student'
                            AND gibbonPerson.status='Full' AND (dateStart IS NULL OR dateStart<=:today) AND (dateEnd IS NULL  OR dateEnd>=:today)
                            ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";
                        $result = $connection2->prepare($sql);
                        $result->execute($data);
                        $students = ($result->rowCount() > 0) ? $result->fetchAll() : [];

                        $form = Form::create('internalAssessment', $session->get('absoluteURL').'/modules/'.$session->get('module').'/atl_write_dataProcess.php?gibbonCourseClassID='.$gibbonCourseClassID.'&atlColumnID='.$atlColumnID);
                        $form->setFactory(DatabaseFormFactory::create($pdo));
                        $form->addHiddenValue('address', $session->get('address'));

                        $form->addRow()->addHeading(__('Assessment Details'));
                        if (empty($students)) {
                            $form->addRow()->addHeading(__('Students'));
                            $form->addRow()->addAlert(__('There are no records to display.'), 'error');
                        } else {
                            $table = $form->addRow()->addTable()->setClass('smallIntBorder fullWidth colorOddEven noMargin noPadding noBorder');

                            $completeText = !empty($atlColumn['completeDate']) ? __('Marked on') . ' ' . Format::date($atlColumn['completeDate']) : __('Unmarked');

                            $header = $table->addHeaderRow();
                                $header->addTableCell(__('Student'))->rowSpan(2);
                                $header->addTableCell($atlColumn['name'])
                                    ->setTitle($atlColumn['description'])
                                    ->append('<br><span class="small emphasis" style="font-weight:normal;">'.$completeText.'</span>')
                                    ->setClass('textCenter')
                                    ->colSpan(3);

                            $header = $table->addHeaderRow();
                                $header->addContent(__('Complete'))->setClass('textCenter');
                                $header->addContent(__('Rubric'))->setClass('textCenter');
                        }

                        foreach ($students as $index => $student) {
                            $count = $index+1;
                            $row = $table->addRow();

                            $row->addWebLink(Format::name('', $student['preferredName'], $student['surname'], 'Student', true))
                                ->setURL($session->get('absoluteURL').'/index.php?q=/modules/Students/student_view_details.php')
                                ->addParam('gibbonPersonID', $student['gibbonPersonID'])
                                ->addParam('subpage', 'Markbook')
                                ->wrap('<strong>', '</strong>')
                                ->prepend($count.') ');

                            $row->addCheckbox('complete'.$count)->setValue('Y')->checked($student['complete'])->setClass('textCenter');

                            $row->addWebLink('<img title="'.__('Mark Rubric').'" src="./themes/'.$session->get('gibbonThemeName').'/img/rubric.png" style="margin-left:4px;"/>')
                            ->setURL($session->get('absoluteURL').'/fullscreen.php?q=/modules/'.$session->get('module').'/atl_write_rubric.php')
                            ->setClass('thickbox textCenter')
                            ->addParam('gibbonRubricID', $atlColumn['gibbonRubricID'])
                            ->addParam('gibbonCourseClassID', $gibbonCourseClassID)
                            ->addParam('gibbonPersonID', $student['gibbonPersonID'])
                            ->addParam('atlColumnID', $atlColumnID)
                            ->addParam('type', 'effort')
                            ->addParam('width', '1100')
                            ->addParam('height', '550');

                            $form->addHiddenValue($count.'-gibbonPersonID', $student['gibbonPersonID']);
                        }

                        $form->addHiddenValue('count', $count);

                        $form->addRow()->addHeading(__('Assessment Complete?'));

                        $row = $form->addRow();
                            $row->addLabel('completeDate', __('Go Live Date'))->prepend('1. ')->append('<br/>'.__('2. Column is hidden until date is reached.'));
                            $row->addDate('completeDate');

                        $row = $form->addRow();
                            $row->addSubmit();

                        $form->loadAllValuesFrom($atlColumn);

                        echo $form->getOutput();
                    }
                }
            }
        }

        //Print sidebar
        $session->set('sidebarExtra', sidebarExtra($gibbonCourseClassID, 'write'));
    }
}