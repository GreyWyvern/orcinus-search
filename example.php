<?php

require 'orcinus/search.php';

?><!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Orcinus Site Search - Online example</title>

  <link rel="stylesheet" href="orcinus/css/search.css">
</head>
<body>
  <h1>Orcinus Site Search - Online example</h1>

  <?php
  // Place this command where your search results should appear
  $_ORCINUS->render(); ?> 

  <!-- Script files below are only required for Typeahead -->
  <script src="orcinus/js/jquery-3.6.4.min.js"></script>
  <script src="orcinus/js/typeahead.bundle.min.js"></script>
  <script src="orcinus/js/search.js"></script>
</body>
</html>
