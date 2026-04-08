<?php
include 'db-connection.php';
session_start();

// Assume user ID is in session (adjust as needed)
$user_id = $_SESSION['user_id'] ?? 1;

$query = "SELECT * FROM individual_table WHERE individual_id = ?";
$stmt = $db_connection->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $phone = $_POST["phone"];
    $street = $_POST["street"];
    $city = $_POST["city"];
    $zipcode = $_POST["zipcode"];

    $updateQuery = "UPDATE individual_table SET 
        individual_phone_number = ?, individual_street = ?, individual_city = ?, individual_zip_code = ?
        WHERE individual_id = ?";

    $stmt = $db_connection->prepare($updateQuery);
    $stmt->bind_param("ssssi", $phone, $street, $city, $zipcode, $user_id);

    if ($stmt->execute()) {
        echo "<p>Profile updated successfully.</p>";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $db_connection->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Edit Profile</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body style="background-image: url('image/log_in-form-bg.jpg'); display: flex; justify-content: center; align-items: center; min-height: 100vh; background-position: center; background-repeat: no-repeat; background-size: cover;">
  <form action="" method="POST"
        class="bg-[#0a1a4dcc] rounded-2xl max-w-5xl w-full p-8 text-white grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-6"
        aria-label="Edit Profile Form">

    <h1 class="md:col-span-2 text-center text-2xl font-bold mb-4 text-white tracking-wide">
      EDIT PROFILE
    </h1>

    <!-- READ-ONLY FIELDS -->
    <div>
      <label class="block text-xs font-bold mb-1">Last Name</label>
      <input type="text" value="<?= htmlspecialchars($user['individual_lastname']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm bg-gray-200" readonly>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">First Name</label>
      <input type="text" value="<?= htmlspecialchars($user['individual_firstname']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm bg-gray-200" readonly>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">Middle Name</label>
      <input type="text" value="<?= htmlspecialchars($user['individual_middlename']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm bg-gray-200" readonly>
    </div>

    <div>
      <label class="block text-xs font-bold mb-1">Gender</label>
      <input type="text" value="<?= htmlspecialchars($user['individual_gender']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm bg-gray-200" readonly>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">Birthday</label>
      <input type="text" value="<?= htmlspecialchars($user['individual_birthday']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm bg-gray-200" readonly>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">Username</label>
      <input type="text" value="<?= htmlspecialchars($user['individual_username']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm bg-gray-200" readonly>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">Email</label>
      <input type="text" value="<?= htmlspecialchars($user['individual_email_address']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm bg-gray-200" readonly>
    </div>

    <!-- EDITABLE FIELDS -->
    <div>
      <label class="block text-xs font-bold mb-1">Phone Number</label>
      <input name="phone" type="text" value="<?= htmlspecialchars($user['individual_phone_number']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm" required>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">Street</label>
      <input name="street" type="text" value="<?= htmlspecialchars($user['individual_street']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm" required>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">City</label>
      <input name="city" type="text" value="<?= htmlspecialchars($user['individual_city']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm" required>
    </div>
    <div>
      <label class="block text-xs font-bold mb-1">Zip Code</label>
      <input name="zipcode" type="text" value="<?= htmlspecialchars($user['individual_zip_code']) ?>" class="w-full rounded-md px-3 py-2 text-black text-sm" required>
    </div>

    <!-- Submit -->
    <div class="md:col-span-2 flex flex-col items-center gap-2 mt-4">
      <button type="submit" class="bg-[#3bb9ff] rounded-full px-8 py-2 text-white font-bold text-sm select-none">
        Save Changes
      </button>
      <a href="all_log-in.php" class="text-xs text-white-300 underline mt-1">Back to Profile</a>
    </div>
  </form>
</body>
</html>
