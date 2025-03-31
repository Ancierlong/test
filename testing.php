<?php
 session_start();
 if (!isset($_SESSION['username'])) {
     header('Location: ../index.php');
     exit;
 }

 $conn = require '../db_connect.php';

 ini_set('display_errors', 1);
 ini_set('display_startup_errors', 1);
 error_reporting(E_ALL);

 $display = "";
 $concepts = [];

 // Fetch approved concept papers for the dropdown
 $query_concepts = "SELECT id, concept_title FROM conceptpapers WHERE approved = 1 ORDER BY concept_title ASC";
 $result_concepts = mysqli_query($conn, $query_concepts);

 if ($result_concepts) {
     while ($row = mysqli_fetch_assoc($result_concepts)) {
         $concepts[$row['id']] = $row['concept_title'];
     }
     mysqli_free_result($result_concepts);
 } else {
     error_log("Error fetching concept papers: " . mysqli_error($conn));
     // Handle error appropriately, maybe display a message
 }


 function getCurrentBalance($conn) {
     $prepared_stmt = $conn->prepare("SELECT balance FROM funds WHERE id = 1");
     if ($prepared_stmt === false) {
         error_log("Error preparing statement to get balance: " . $conn->error);
         return false;
     }
     if (!$prepared_stmt->execute()) {
          error_log("Error executing statement to get balance: " . $prepared_stmt->error);
          $prepared_stmt->close();
          return false;
     }
     $result = $prepared_stmt->get_result();
     if ($row = $result->fetch_assoc()) {
         $prepared_stmt->close(); // Close statement here
         return $row['balance'];
     } else {
         // If no row exists (e.g., table is empty), return 0 or handle as error
         $prepared_stmt->close();
         error_log("No balance found in funds table for id = 1.");
         // You might want to insert an initial record if none exists, or return a default
         return 0.00;
     }
 }

 function updateBalanceAndLog($conn, $concept_id, $type, $amount, $userid, $concepts) {
     global $display;
     date_default_timezone_set("Asia/Manila");
     $datetime = date("Y-m-d H:i:s");

     // Validate amount is positive
     if ($amount <= 0) {
          $display = "<h2 style='color:red;'>Amount must be a positive number.</h2>";
          return;
     }

     // Validate concept_id exists in $concepts array OR is 0 (General Fund)
     if ($concept_id != 0 && !isset($concepts[$concept_id])) {
         $display = "<h2 style='color:red;'>Invalid concept selected.</h2>";
         return;
     }

     // Start transaction for atomicity
     mysqli_begin_transaction($conn);

     try {
         // Fetch current balance within the transaction using FOR UPDATE for locking
         $stmt_get_balance = $conn->prepare("SELECT balance FROM funds WHERE id = 1 FOR UPDATE");
          if (!$stmt_get_balance) {
              throw new Exception("Error preparing get balance statement: " . $conn->error);
          }
          if (!$stmt_get_balance->execute()) {
               throw new Exception("Error executing get balance statement: " . $stmt_get_balance->error);
          }
          $result_balance = $stmt_get_balance->get_result();
          if (!($row_balance = $result_balance->fetch_assoc())) {
               // Handle case where funds row doesn't exist if necessary
               throw new Exception("Funds record not found.");
          }
          $current_balance = $row_balance['balance'];
          $stmt_get_balance->close();


         // Calculate new balance
         $new_balance = ($type === 'credit') ? $current_balance + $amount : $current_balance - $amount;

         // Prevent balance going below zero for debits
         if ($type === 'debit' && $new_balance < 0) {
             throw new Exception("Insufficient funds for this debit transaction.");
         }

         // Update balance
         $stmt_update = $conn->prepare("UPDATE funds SET balance = ? WHERE id = 1");
         if (!$stmt_update) {
             throw new Exception("Error preparing UPDATE statement: " . $conn->error);
         }
         $stmt_update->bind_param("d", $new_balance);
         if (!$stmt_update->execute()) {
             throw new Exception("Error executing UPDATE statement: " . $stmt_update->error);
         }
         $stmt_update->close();


         // Log transaction
         $log_query = "INSERT INTO logs_funds
                                   (concept_id, transaction_type, amount, transaction_date, user_id, previous_balance, new_balance)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)";
         $stmt_log = $conn->prepare($log_query);
         if (!$stmt_log) {
             throw new Exception("Error preparing log statement: " . $conn->error);
         }

         // Use 0 for concept_id if it's 0 (General Fund), otherwise cast to int
         $concept_id_for_db = ($concept_id == 0) ? 0 : (int)$concept_id;
         // Bind params carefully - use 'sdsidd' if concept_id is int, or handle NULL appropriately
         // Since concept_id can be NULL, binding as integer 'i' might cause issues if $concept_id_for_db is null.
         // It's often safer to treat it carefully or adjust table schema (allow NULL for concept_id FK)
         // Let's assume concept_id in logs_funds can be NULL. We need to bind differently or adjust query slightly.
         // A common approach if concept_id can be NULL:
         $stmt_log->bind_param("isdsidd", $concept_id_for_db, $type, $amount, $datetime, $userid, $current_balance, $new_balance); // Assuming user_id is int ('i')


         if (!$stmt_log->execute()) {
             throw new Exception("Error executing log statement: " . $stmt_log->error);
         }
         $stmt_log->close();

         // If all successful, commit transaction
         mysqli_commit($conn);
         $display .= "<h2 style='color:green;'>Balance updated and logged successfully! New Balance: " . number_format($new_balance, 2) . "</h2>";

     } catch (Exception $e) {
         // If any error occurred, roll back transaction
         mysqli_rollback($conn);
         error_log("Transaction failed: " . $e->getMessage());
         $display .= "<h2 style='color:red;'>Transaction failed: " . htmlspecialchars($e->getMessage()) . "</h2>";
         // Close any potentially open statements in catch block if necessary
          if (isset($stmt_get_balance) && $stmt_get_balance instanceof mysqli_stmt) $stmt_get_balance->close();
          if (isset($stmt_update) && $stmt_update instanceof mysqli_stmt) $stmt_update->close();
          if (isset($stmt_log) && $stmt_log instanceof mysqli_stmt) $stmt_log->close();

     }
 }


 if (isset($_POST['general_fund_submit'])) {
     $amount = isset($_POST['general_fund_amount']) ? floatval($_POST['general_fund_amount']) : 0;
     $type = isset($_POST['general_fund_type']) ? $_POST['general_fund_type'] : null;
     $userid = $_SESSION['user_id']; // Make sure user_id is set in session

     if ($type && $amount > 0 && isset($userid)) {
         updateBalanceAndLog($conn, 0, $type, $amount, $userid, $concepts); // Pass 0 for general fund
     } else {
          $display = "<h2 style='color:red;'>Invalid input for general fund adjustment.</h2>";
     }
 }

 if (isset($_POST['credit_debit_submit'])) {
     // Use filter_input for better security/validation
     $concept_id_for_log = filter_input(INPUT_POST, 'concept_title_select', FILTER_VALIDATE_INT);
     $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
     $type = filter_input(INPUT_POST, 'transaction_type', FILTER_SANITIZE_STRING); // Basic sanitization
     $userid = $_SESSION['user_id']; // Make sure user_id is set in session

      // Additional validation
     if ($concept_id_for_log !== false && $amount !== false && $amount > 0 && ($type === 'credit' || $type === 'debit') && isset($userid)) {
          if ($concept_id_for_log === null) { // Check if filter returned null (means it wasn't a valid int or wasn't set)
               $display = "<h2 style='color:red;'>Please select a valid Concept Paper.</h2>";
          } else {
               updateBalanceAndLog($conn, $concept_id_for_log, $type, $amount, $userid, $concepts);
          }
     } else {
         $display = "<h2 style='color:red;'>Invalid input for credit/debit operation. Please check amount and selections.</h2>";
     }
 }

 // Fetch ALL log data - DataTables will handle pagination/sorting client-side
 $logs = [];
 $log_query = "SELECT
                         lf.transaction_date,
                         u.username,
                         c.concept_title,
                         c.concept_date,
                         lf.transaction_type,
                         lf.amount,
                         lf.previous_balance,
                         lf.new_balance
                      FROM logs_funds lf
                      JOIN users u ON lf.user_id = u.id
                      LEFT JOIN conceptpapers c ON lf.concept_id = c.id
                      ORDER BY lf.transaction_date DESC";

 $log_result = mysqli_query($conn, $log_query);
 if ($log_result) {
     while ($row = mysqli_fetch_assoc($log_result)) {
         $logs[] = $row;
     }
     mysqli_free_result($log_result);
 } else {
     error_log("Error fetching logs: " . mysqli_error($conn));
     $display .= "<h2 style='color:orange;'>Could not fetch transaction logs.</h2>";
 }

 mysqli_close($conn); // Close connection after all DB operations

 ?>

 <!DOCTYPE html>
 <html lang="en">
 <head>
     <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <title>CCS Department Database Management System | Manage Concept Funds</title>

     <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

     <style>
         /* Your existing styles */
         body {
             font-family: Arial, sans-serif;
             background-color: #7F1416; /* Original background color */
             margin: 0; /* Reset margin */
             padding: 20px; /* Add padding around the body */
             display: flex;                 /* Use flexbox for centering */
             justify-content: center; /* Center horizontally */
             align-items: flex-start; /* Align items to the top */
             min-height: 100vh;             /* Ensure body takes full viewport height */
         }

         .manage-balance-container {
             background-color: #fff;
             border-radius: 4px;
             box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
             padding: 25px;
             width: 90%; /* Use percentage for responsiveness */
             max-width: 1000px; /* Max width to prevent it getting too wide */
             margin: 20px auto; /* Add margin top/bottom and auto left/right */
         }

         .manage-balance-container h2 {
             text-align: center;
             margin-bottom: 20px;
             color: #333; /* Darker heading color */
         }

         .manage-balance-container hr {
             border: none;
             height: 1px;
             background-color: #ddd;
             margin-top: 20px;
             margin-bottom: 20px;
         }

         .manage-balance-container label {
             font-size: 13px;
             font-weight: bold;
             display: block;
             margin-bottom: 5px;
             color: #555; /* Slightly lighter label color */
         }

         .manage-balance-container input[type="number"],
         .manage-balance-container select {
             width: 100%; /* Make inputs/selects take full width of their container */
             padding: 9px; /* Slightly larger padding */
             margin-bottom: 15px;
             border: 1px solid #ccc;
             border-radius: 4px;
             box-sizing: border-box; /* Include padding and border in element's total width/height */
             font-size: 14px; /* Standard font size */
         }

         .manage-balance-container input[type="submit"] {
             background-color: #007BFF;
             font-size: 16px;
             color: #fff;
             padding: 12px 25px; /* Larger padding for button */
             border: none;
             border-radius: 4px;
             cursor: pointer;
             width: auto;
             transition: background-color 0.3s ease;
             display: inline-block; /* Align button properly */
         }

         .manage-balance-container input[type="submit"]:hover {
             background-color: #0056b3;
         }

         .manage-balance-container .button.backtodash { /* Specific styling for back button */
             display: block; /* Make it block level */
             width: fit-content; /* Width based on content */
             margin: 20px auto 0 auto; /* Center horizontally, add top margin */
             text-align: center;
             padding: 10px 20px;
             background-color: #6c757d; /* Grey color for back button */
             color: #fff;
             text-decoration: none;
             border: none;
             border-radius: 4px;
             cursor: pointer;
             transition: background-color 0.3s ease;
         }

         .manage-balance-container .button.backtodash:hover {
              background-color: #5a6268; /* Darker grey on hover */
         }


         .form-section {
             margin-bottom: 25px; /* Increased spacing */
             padding: 20px; /* Increased padding */
             border: 1px solid #eee;
             border-radius: 4px;
             background-color: #f9f9f9;
         }

         .form-section h3 {
             margin-top: 0;
             margin-bottom: 15px; /* Increased spacing */
             text-align: center;
             color: #333;
             font-size: 1.2em; /* Slightly larger heading */
         }

         .form-inline {
             display: flex;
             flex-wrap: wrap; /* Allow wrapping on smaller screens */
             gap: 15px; /* Increased gap */
             align-items: center;
             margin-bottom: 15px;
         }

         .form-inline label {
              flex-basis: 150px; /* Adjust basis for labels */
              text-align: right;
              margin-bottom: 0; /* Remove bottom margin since gap handles spacing */
              padding-right: 10px; /* Add padding for spacing */
         }

         .form-inline > input,
         .form-inline > select {
             flex-grow: 1; /* Allow input/select to grow */
              min-width: 200px; /* Minimum width for inputs/selects */
         }

         .form-inline small {
              flex-basis: 100%; /* Make small text take full width */
              text-align: left; /* Align text left */
              margin-left: 165px; /* Align with input fields (label basis + gap approx) */
              color: #666;
              font-size: 12px;
         }


         .log-list-section {
             margin-top: 30px;
             padding: 20px; /* Increased padding */
             border: 1px solid #eee;
             border-radius: 4px;
             background-color: #f9f9f9;
         }

         .log-list-section h3 {
             margin-top: 0;
             margin-bottom: 15px; /* Increased spacing */
             text-align: center;
              color: #333;
             font-size: 1.2em;
         }

         /* Style DataTables elements */
         .dataTables_wrapper {
             font-size: 14px;
         }
         .dataTables_filter label,
         .dataTables_length label {
             font-weight: normal;
             margin-bottom: 10px; /* Add space below search/length */
             display: inline-flex; /* Better alignment */
             align-items: center;
         }
          .dataTables_filter input {
              margin-left: 0.5em;
              padding: 6px;
              border: 1px solid #ccc;
              border-radius: 4px;
         }
          .dataTables_length select {
              margin-left: 0.5em;
              margin-right: 0.5em;
              padding: 6px;
              border: 1px solid #ccc;
              border-radius: 4px;
         }

         .log-table {
             width: 100%;
             border-collapse: collapse;
             margin-bottom: 15px; /* Add space below table */
         }

         .log-table th, .log-table td {
             border: 1px solid #ddd;
             padding: 10px; /* Increased padding */
             text-align: left;
             vertical-align: middle; /* Align text vertically center */
         }

         .log-table th {
             background-color: #f2f2f2;
             font-weight: bold;
             cursor: pointer; /* Indicate sortable columns */
         }
         .log-table tbody tr:nth-child(odd) {
              background-color: #fdfdfd; /* Slightly off-white for odd rows */
         }
         .log-table tbody tr:hover {
             background-color: #e9e9e9; /* Highlight row on hover */
         }
          /* Style for pagination buttons */
         .dataTables_paginate .paginate_button {
             padding: 6px 12px;
             margin: 0 2px;
             border: 1px solid #ddd;
             border-radius: 3px;
             cursor: pointer;
             background-color: #fff;
             color: #337ab7; /* Bootstrap primary blue */
         }
         .dataTables_paginate .paginate_button:hover {
              background-color: #eee;
              border-color: #ddd;
              text-decoration: none;
         }
          .dataTables_paginate .paginate_button.current {
             background-color: #337ab7;
             color: #fff;
             border-color: #337ab7;
         }
          .dataTables_paginate .paginate_button.disabled,
          .dataTables_paginate .paginate_button.disabled:hover {
              color: #999;
              background-color: #fff;
              border-color: #ddd;
              cursor: default;
         }

         /* Center align submit buttons */
         .form-section > form > div:last-child {
             text-align: center;
             margin-top: 10px; /* Add some space above the button */
         }


     </style>
 </head>
 <body>
 <div class="manage-balance-container">
     <h2>Manage General Funds</h2>
     <?php echo $display; // Display feedback messages ?>
     <hr>

     <div class="form-section">
         <h3>Adjust General Fund Balance</h3>
         <form method="post" action="">
             <div class="form-inline">
                 <label for="general_fund_type">Action:</label>
                 <select id="general_fund_type" name="general_fund_type" required>
                     <option value="credit">Add to Balance (Credit)</option>
                     <option value="debit">Subtract from Balance (Debit)</option>
                 </select>
             </div>
             <div class="form-inline">
                 <label for="general_fund_amount">Enter Amount:</label>
                 <input type="number" id="general_fund_amount" name="general_fund_amount" step="0.01" min="0.01" required>
             </div>
             <div>
                 <input type="submit" name="general_fund_submit" value="Update General Fund">
             </div>
         </form>
     </div>

     <div class="form-section">
         <h3>Credit / Debit Amount (Related to Concept Paper)</h3>
         <form method="post" action="">
             <div class="form-inline">
                 <label for="amount">Enter Amount:</label>
                 <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
             </div>
             <div class="form-inline">
                 <label for="transaction_type">Transaction Type:</label>
                 <select id="transaction_type" name="transaction_type" required>
                     <option value="credit">Credit (Add)</option>
                     <option value="debit">Debit (Subtract)</option>
                 </select>
             </div>
             <div class="form-inline">
                 <label for="concept_title_select">Related Concept Paper:</label>
                 <select id="concept_title_select" name="concept_title_select" required>
                     <option value="" disabled selected>-- Select a Concept Paper --</option>
                     <?php if (!empty($concepts)): ?>
                         <?php foreach ($concepts as $id => $title): ?>
                             <option value="<?php echo htmlspecialchars($id); ?>">
                                  <?php echo htmlspecialchars($title); ?>
                             </option>
                         <?php endforeach; ?>
                     <?php else: ?>
                           <option value="" disabled>No approved concept papers found</option>
                     <?php endif; ?>
                      </select>
                 <small>Select the concept paper related to this transaction.</small>
             </div>
             <div>
                 <input type="submit" name="credit_debit_submit" value="Update Balance (Concept Related)">
             </div>
         </form>
     </div>

     <div class="log-list-section">
         <h3>Transaction Logs</h3>
         <?php if (!empty($logs)): ?>
             <table id="logTable" class="log-table display"> <thead>
                 <tr>
                     <th>Date</th>
                     <th>User</th>
                     <th>Concept Paper</th>
                     <th>Concept Date</th>
                     <th>Type</th>
                     <th>Amount</th>
                     <th>Previous Balance</th>
                     <th>New Balance</th>
                 </tr>
             </thead>
                 <tbody>
                     <?php foreach ($logs as $log): ?>
                         <tr>
                             <td><?php echo htmlspecialchars($log['transaction_date']); ?></td>
                             <td><?php echo htmlspecialchars($log['username']); ?></td>
                             <td>
                                  <?php echo htmlspecialchars($log['concept_title'] ?: 'General Fund'); ?>
                             </td>
                             <td>
                                  <?php echo $log['concept_date'] ? htmlspecialchars(date('Y-m-d', strtotime($log['concept_date']))) : 'N/A'; ?>
                             </td>
                             <td><?php echo htmlspecialchars(ucfirst($log['transaction_type'])); ?></td>
                              <td data-sort="<?php echo $log['amount']; ?>"><?php echo htmlspecialchars(number_format($log['amount'], 2)); ?></td>
                             <td data-sort="<?php echo $log['previous_balance']; ?>"><?php echo htmlspecialchars(number_format($log['previous_balance'], 2)); ?></td>
                             <td data-sort="<?php echo $log['new_balance']; ?>"><?php echo htmlspecialchars(number_format($log['new_balance'], 2)); ?></td>
                         </tr>
                     <?php endforeach; ?>
                 </tbody>
             </table>
         <?php else: ?>
             <p>No transaction logs found.</p>
         <?php endif; ?>
     </div>

     <hr>
     <a href="../dashboard.php" class="button backtodash">Back to Dashboard</a>
 </div>

 <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
 <script type="text/javascript" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

 <script>
 $(document).ready(function() {
     $('#logTable').DataTable({
         "paging": true,          // Enable pagination
         "pageLength": 5,         // Show 5 entries per page
         "lengthChange": true, // Allow user to change number of entries shown
          "lengthMenu": [ [5, 10, 25, 50, -1], [5, 10, 25, 50, "All"] ], // Options for length change
         "ordering": true,          // Enable sorting
         "info": true,            // Show table information (e.g., "Showing 1 to 5 of X entries")
         "searching": true,       // Enable search/filtering box
         "order": [[ 0, "desc" ]], // Initial sort: First column (Date) descending
          "columnDefs": [
              // Disable sorting for concept date if needed, or specify types
              { "orderable": false, "targets": 3 }, // Example: Make 'Concept Date' (index 3) not sortable
              // Ensure numeric columns are treated as numbers for sorting
              { "type": "num-fmt", "targets": [5, 6, 7] } // Apply numeric formatting sort to Amount, Prev Balance, New Balance
          ]
     });
 });
 </script>

 </body>
 </html>
