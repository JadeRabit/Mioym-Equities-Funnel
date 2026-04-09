<?php
$hash_to_check = '$2y$10$ZA91B9tgs9mNAWnY8KpVKuQfwKtzTTGJkTj.KTGPwgKINzk6RCB26';
$message = '';

if (isset($_POST['password'])) {
    $password_guess = $_POST['password'];
    if (password_verify($password_guess, $hash_to_check)) {
        $message = "Password is correct!";
    } else {
        $message = "Password is incorrect.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Password Debugger</title>
</head>
<body>

    <h1>Password Debugger</h1>

    <p>Enter a password to check against the hash:</p>
    <p><strong>Hash:</strong> <?php echo $hash_to_check; ?></p>

    <form method="POST" action="">
        <label for="password">Password:</label>
        <input type="password" id="password" name="password" required>
        <button type="submit">Check Password</button>
    </form>

    <?php if ($message): ?>
        <h2><?php echo $message; ?></h2>
    <?php endif; ?>

</body>
</html>
