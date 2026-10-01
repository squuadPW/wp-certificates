<!-- Your modal content here -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">

<div class="modal-content modal-enrollment" id="modal-content">
    <div id="modal-contraseña" class="modal" style="overflow: auto; padding: 0 !important">
        <div class="modal-content modal-enrollment">
            <span id="close-modal-enrollment" style="float: right; cursor: pointer"><span
                    class='dashicons dashicons-no-alt'></span></span>
            <div class="modal-body" id="content-pdf">
                <div id="part1" style="font-size: 12px; color: #000">
                    <div style="padding: 0 !important; text-align: center; border: 1px solid gray;">
                        <?php include(EDUSYSTEM_PATH . 'public/templates/img-logo.php'); ?>
                        <span style="font-size: 12px;">3105 NW 107 Avenue, Doral, FL 33172 | (786) 361 – 9307 |
                            www.American-elite.us</span>
                        <h4 style="padding: 4px; text-align: center; font-weight: bold; font-size: 20px;">ENROLLMENT
                            AGREEMENT</h4>
                    </div>
                    <div>
                        <input type="hidden" name="student_user_id" value="<?php echo $student_id ?>">
                        <input type="hidden" name="parent_user_id" value="<?php echo $partner_id ?>">
                        <input type="hidden" name="show_parent_info" value="<?php echo $show_parent_info ?>">
                        <input class="formdata" autocomplete="off" type="hidden" id="modal_open" name="modal_open" value="1">
                        <h4
                            style="background-color: #002fbd; color: white; padding: 4px; text-align: center; font-weight: 600;">
                            STUDENT PERSONAL INFORMATION</h4>
                        <div style="display: flex">
                            <div style="flex: 50%; border: 1px solid gray; padding: 8px;"><strong>Complete
                                    name:</strong>
                                <br> <?php echo $user['student_full_name'] ?>
                            </div>
                            <div style="flex: 50%; border: 1px solid gray; padding: 8px;"><strong>Date:</strong>
                                <br> <?php echo $user['student_created_at'] ?>
                            </div>
                        </div>
                        <div style="display: flex">
                            <div style="flex: 50%; border: 1px solid gray; padding: 8px;"><strong>Date of
                                    Birth:</strong>
                                <br> <?php echo $user['student_birth_date'] ?>
                            </div>
                            <div style="flex: 50%; border: 1px solid gray; padding: 8px;"><strong>Gender:</strong>
                                <br> <?php echo $user['student_gender'] ?>
                            </div>
                        </div>
                        <div style="display: flex">
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;"><strong>Address:</strong>
                                <br> <?php echo $user['student_address'] ?>
                            </div>
                        </div>
                        <div style="display: flex">
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;"><strong>Country:</strong>
                                <br> <?php echo $user['student_country'] ?>
                            </div>
                        </div>
                        <div style="display: flex">
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;"><strong>Phone:</strong>
                                <br> <?php echo $user['student_phone'] ?>
                            </div>
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;"><strong>Cell:</strong>
                                <br> <?php echo $user['student_phone'] ?>
                            </div>
                            <?php if ($show_parent_info == 1) { ?>
                                <div style="flex: auto; border: 1px solid gray; padding: 8px;"><strong>Parent cell:</strong>
                                    <br> <?php echo $user['parent_cell'] ?>
                                </div>
                            <?php } ?>
                        </div>
                        <div style="display: flex">
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;">
                                <?php if ($show_parent_info == 1) { ?>
                                    <strong>Identification # or
                                        Passport # of parent:</strong>
                                    <br> <?php echo $user['parent_identification'] ?>
                                    <br><br>
                                <?php } ?>

                                <strong>Identification or Passport # if child is above 16:</strong>
                                <br> <?php echo $user['student_identification'] ?>
                            </div>
                        </div>
                        <?php if ($show_parent_info == 1) { ?>
                            <div style="display: flex">
                                <div style="flex: auto; border: 1px solid gray; padding: 8px;"><strong>Parent/Legal Guardian
                                        Full Name:</strong>
                                    <br> <?php echo $user['parent_full_name'] ?>
                                    <br><br><strong>Email (Required to access parent portal):</strong>
                                    <br> <?php echo $user['parent_email'] ?>
                                </div>
                            </div>
                        <?php } ?>

                    </div>
                </div>
                <div id="part2" style="font-size: 12px; color: #000">
                    <div>
                        <h4
                            style="background-color: #002fbd; color: white; padding: 4px; text-align: center; font-weight: 600;">
                            SCHOOL INFORMATION</h4>
                        <div style="display: flex">
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;">
                                <strong>Name of Last or Concurrent School:</strong>
                                <?php echo isset($institute) ? $institute->name : $institute_name ?> <br><br>
                                <strong>School Address:</strong>
                                <?php echo isset($institute) ? $institute->address : '' ?>
                                <br><br>
                                <strong>Phone:</strong> <?php echo isset($institute) ? $institute->phone : '' ?>
                                <br><br>
                                <strong>Last date Attended:</strong> <br><br>
                                <strong id="select_grade">Last completed grade:</strong> <br>
                                <span onclick="updateGrade('grade5')" style="cursor: pointer"><span id="grade5">(
                                        )</span>
                                    5th Grade</span>
                                <span onclick="updateGrade('grade6')" style="cursor: pointer"><span id="grade6">(
                                        )</span>
                                    6th Grade</span>
                                <span onclick="updateGrade('grade7')" style="cursor: pointer"><span id="grade7">(
                                        )</span>
                                    7th Grade</span>
                                <span onclick="updateGrade('grade8')" style="cursor: pointer"><span id="grade8">(
                                        )</span>
                                    8th Grade</span>
                                <span onclick="updateGrade('grade9')" style="cursor: pointer"><span id="grade9">(
                                        )</span>
                                    9th Grade</span>
                                <span onclick="updateGrade('grade10')" style="cursor: pointer"><span id="grade10">(
                                        )</span>
                                    10th Grade</span>
                                <span onclick="updateGrade('grade11')" style="cursor: pointer"><span id="grade11">(
                                        )</span>
                                    11th Grade</span>
                                <span onclick="updateGrade('grade12')" style="cursor: pointer"><span id="grade12">(
                                        )</span>
                                    12th Grade</span>
                                <br><br>
                                <strong id="please_select_grade" style="color: red; display: none">Please select a grade
                                    to
                                    continue</strong>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h4
                            style="background-color: #002fbd; color: white; padding: 4px; text-align: center; font-weight: 600;">
                            METHOD OF TUITION PAYMENT</h4>
                        <div style="display: flex">
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;">
                                [<?php echo $user['student_payment'] == '2' ? '✓' : '  ' ?>] Full Academic year payment
                                at
                                time of signing enrollment agreement. <br>
                                [<?php echo $user['student_payment'] == '1' ? '✓' : '  ' ?>] Balance paid prior to
                                graduation through payment plan, according to an agreed upon
                                schedule. Final transcript and diploma is
                                contingent upon full balance payment.
                            </div>
                        </div>
                    </div>
                    <div>
                        <h4
                            style="background-color: #002fbd; color: white; padding: 4px; text-align: center; font-weight: 600;">
                            METHOD OF TUITION PAYMENT</h4>
                        <div style="display: flex">
                            <div style="flex: auto; border: 1px solid gray; padding: 8px;">
                                <p>By signing the agreement herein, the student and their parent/guardian enter into
                                    agreement with
                                    American Elite School (AES), under
                                    which the student/parent/guardian will pay tuition and fees and adhere to the
                                    school’s
                                    policies
                                    including refund policies as set forth in
                                    AES website and catalog. In signing this application, the student understands that
                                    successful
                                    completion of all courses and exams and
                                    full payment are required for graduation. The student also certifies that the
                                    information
                                    included
                                    on this form is correct.
                                </p> <br>

                                <p style="color: red; font-style: italic">PLEASE NOTE: You must enroll in a course
                                    within
                                    the first
                                    three months of
                                    acceptance to the program. Failure to do so will result in
                                    being dropped from the program and you will have to reapply for admission.</p><br>

                                <input type="hidden" name="auto_signature_student" value="0">
                                <div class="signatures_squares">
                                    <div class="signature_square_field">
                                        <div>
                                            <div style="padding: 8px;"><strong>Applicant Full Name:</strong>
                                                <br> <?php echo $user['student_full_name'] ?>
                                            </div>
                                        </div>
                                        <div style="position: relative; padding: 8px;" id="signature-pad-student">
                                            <canvas id="signature-student" width="100%" height="200"
                                                style="border: 1px solid gray; margin: auto !important; background-color: #ffff005c"></canvas>
                                            <div id="sign-here-student"
                                                style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                                                <span>SIGN HERE</span>
                                            </div>
                                        </div>
                                        <button id="clear-student" style="width: 100%;">Clear</button>
                                        <button id="generate-signature-student" style="width: 100%;"
                                            onclick="autoSignature('signature-pad-student', 'signature-text-student', 'generate-signature-student', 'clear-student')">Generate
                                            signature automatically</button>
                                        <div style="    position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                                            id="signature-text-student">
                                            <div
                                                style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                                                <?php echo $user['student_signature'] ?></div>
                                        </div>
                                        <button id="clear-student-signature"
                                            style="width: 100%; display: none">Cancel</button>
                                    </div>
                                    <?php if ($show_parent_info == 1) { ?>
                                        <input type="hidden" name="auto_signature_parent" value="0">
                                        <div class="signature_square_field">
                                            <div>
                                                <div style="padding: 8px;"><strong>Parent/Legal Guardian Full Name:</strong>
                                                    <br> <?php echo $user['parent_full_name'] ?>
                                                </div>
                                            </div>
                                            <div style="position: relative; padding: 8px;" id="signature-pad-parent">
                                                <canvas id="signature-parent" width="100%" height="200"
                                                    style="border: 1px solid gray; margin: auto !important;  background-color: #ffff005c"></canvas>
                                                <div id="sign-here-parent"
                                                    style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                                                    <span>SIGN HERE</span>
                                                </div>
                                            </div>
                                            <button id="clear-parent" style="width: 100%;">Clear</button>
                                            <button id="generate-signature-parent" style="width: 100%;"
                                                onclick="autoSignature('signature-pad-parent', 'signature-text-parent', 'generate-signature-parent', 'clear-parent')">Generate
                                                signature automatically</button>
                                            <div style="    position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                                                id="signature-text-parent">
                                                <div
                                                    style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                                                    <?php echo $user['parent_full_name'] ?></div>
                                            </div>
                                            <button id="clear-parent-signature"
                                                style="width: 100%; display: none">Cancel</button>
                                        </div>
                                    <?php } ?>
                                </div>
                                <div style="display: flex">
                                    <div style="flex: auto; padding: 8px;"><strong>Date:</strong>
                                        <br><?php echo $user['student_created_at'] ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="text-align: center; display: block">
                <button type="button" class="submit button-create-enrollment" id="saveSignatures">Save</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
        integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</div>