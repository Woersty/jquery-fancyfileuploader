<?php
	// Fancy File Uploader helper class.  Combines some useful functions from FlexForms and FlexForms Modules.
	// (C) 2020 CubicleSoft.  All Rights Reserved.

	class FancyFileUploaderHelper
	{
		// Copy included for class self-containment.
		// Makes an input filename safe for use.
		// Allows a very limited number of characters through.
		public static function FilenameSafe($filename)
		{
			// Added Unicode support for some characters äöü... and allow spaces 
			return preg_replace('/[^A-Za-z0-9\_\ \.\-\x{00C0}-\x{00FF}]/u', "_", $filename);
		}

		public static function NormalizeFiles($key)
		{
			$result = array();
			if (isset($_FILES) && is_array($_FILES) && isset($_FILES[$key]) && is_array($_FILES[$key]))
			{
				$currfiles = $_FILES[$key];

				if (isset($currfiles["name"]) && isset($currfiles["type"]) && isset($currfiles["tmp_name"]) && isset($currfiles["error"]) && isset($currfiles["size"]))
				{
					if (is_string($currfiles["name"]))
					{
						$currfiles["name"] = array($currfiles["name"]);
						$currfiles["type"] = array($currfiles["type"]);
						$currfiles["tmp_name"] = array($currfiles["tmp_name"]);
						$currfiles["error"] = array($currfiles["error"]);
						$currfiles["size"] = array($currfiles["size"]);
					}

					$y = count($currfiles["name"]);
					for ($x = 0; $x < $y; $x++)
					{
						if ($currfiles["error"][$x] != 0)
						{
							switch ($currfiles["error"][$x])
							{
								case 1:  $msg = "UPLOAD_ERR_INI_SIZE";   $code = "upload_err_ini_size";   break;
								case 2:  $msg = "UPLOAD_ERR_FORM_SIZE";  $code = "upload_err_form_size";  break;
								case 3:  $msg = "UPLOAD_ERR_PARTIAL";    $code = "upload_err_partial";    break;
								case 4:  $msg = "UPLOAD_ERR_NO_FILE";    $code = "upload_err_no_file";    break;
								case 6:  $msg = "UPLOAD_ERR_NO_TMP_DIR"; $code = "upload_err_no_tmp_dir"; break;
								case 7:  $msg = "UPLOAD_ERR_CANT_WRITE"; $code = "upload_err_cant_write"; break;
								case 8:  $msg = "UPLOAD_ERR_EXTENSION";  $code = "upload_err_extension";  break;
								default: $msg = "UPLOAD_ERR_UNKNOWN";    $code = "upload_err_unknown";    break;
							}

							$entry = array(
								"success" => false,
								"error" => self::FFTranslate($msg),
								"errorcode" => $code
							);
						}
						else if (!is_uploaded_file($currfiles["tmp_name"][$x]))
						{
							$entry = array(
								"success" => false,
								"error" => self::FFTranslate("INVALID_INPUT_FILENAME"),
								"errorcode" => "invalid_input_filename"
							);
						}
						else
						{
							$currfiles["name"][$x] = self::FilenameSafe($currfiles["name"][$x]);
							$pos = strrpos($currfiles["name"][$x], ".");
							$fileext = ($pos !== false ? (string)substr($currfiles["name"][$x], $pos + 1) : "");

							$entry = array(
								"success" => true,
								"file" => $currfiles["tmp_name"][$x],
								"name" => $currfiles["name"][$x],
								"ext" => $fileext,
								"type" => $currfiles["type"][$x],
								"size" => $currfiles["size"][$x]
							);
						}

						$result[] = $entry;
					}
				}
			}

			return $result;
		}

		public static function GetMaxUploadFileSize()
		{
			$maxpostsize = floor(self::ConvertUserStrToBytes(ini_get("post_max_size")) * 3 / 4);
			if ($maxpostsize > 4096)  $maxpostsize -= 4096;

			$maxuploadsize = self::ConvertUserStrToBytes(ini_get("upload_max_filesize"));
			if ($maxuploadsize < 1)  $maxuploadsize = ($maxpostsize < 1 ? -1 : $maxpostsize);

			return ($maxpostsize < 1 ? $maxuploadsize : min($maxpostsize, $maxuploadsize));
		}

		// Copy included for FlexForms self-containment.
		public static function ConvertUserStrToBytes($str)
		{
			$str = trim($str);
			$num = (double)$str;
			if (strtoupper(substr($str, -1)) == "B")  $str = substr($str, 0, -1);
			switch (strtoupper(substr($str, -1)))
			{
				case "P":  $num *= 1024;
				case "T":  $num *= 1024;
				case "G":  $num *= 1024;
				case "M":  $num *= 1024;
				case "K":  $num *= 1024;
			}

			return $num;
		}

		public static function GetChunkFilename()
		{
			if (isset($_SERVER["HTTP_CONTENT_DISPOSITION"]))
			{
				// Content-Disposition: attachment; filename="urlencodedstr"
				$str = $_SERVER["HTTP_CONTENT_DISPOSITION"];
				if (strtolower(substr($str, 0, 11)) === "attachment;")
				{
					$pos = strpos($str, "\"", 11);
					$pos2 = strrpos($str, "\"");

					if ($pos !== false && $pos2 !== false && $pos < $pos2)
					{
						$str = self::FilenameSafe(rawurldecode(substr($str, $pos + 1, $pos2 - $pos - 1)));

						if ($str !== "")  return $str;
					}
				}
			}

			return false;
		}

		public static function GetFileStartPosition()
		{
			if (isset($_SERVER["HTTP_CONTENT_RANGE"]) || isset($_SERVER["HTTP_RANGE"]))
			{
				// Content-Range: bytes (*|integer-integer)/(*|integer-integer)
				$vals = explode(" ", preg_replace('/\s+/', " ", str_replace(",", "", (isset($_SERVER["HTTP_CONTENT_RANGE"]) ? $_SERVER["HTTP_CONTENT_RANGE"] : $_SERVER["HTTP_RANGE"]))));
				if (count($vals) === 2 && strtolower($vals[0]) === "bytes")
				{
					$vals = explode("/", trim($vals[1]));
					if (count($vals) === 2)
					{
						$vals = explode("-", trim($vals[0]));

						if (count($vals) === 2)  return (double)$vals[0];
					}
				}
			}

			return 0;
		}

		public static function HandleUpload($filekey, $options = array())
		{
			if (!isset($_REQUEST["fileuploader"]) && !isset($_POST["fileuploader"]))  return false;

			header("Content-Type: application/json");

			if (isset($options["allowed_exts"]))
			{
				$allowedexts = array();

				if (is_string($options["allowed_exts"]))  $options["allowed_exts"] = explode(",", $options["allowed_exts"]);

				foreach ($options["allowed_exts"] as $ext)
				{
					$ext = strtolower(trim(trim($ext), "."));
					if ($ext !== "")  $allowedexts[$ext] = true;
				}
			}

			$files = self::NormalizeFiles($filekey);
			if (!isset($files[0]))  $result = array("success" => false, "error" => self::FFTranslate("BAD_INPUT"), "errorcode" => "bad_input");
			else if (!$files[0]["success"])  $result = $files[0];
			else if (isset($options["allowed_exts"]) && !isset($allowedexts[strtolower($files[0]["ext"])]))
			{
				$result = array(
					"success" => false,
					"error" => self::FFTranslate("INVALID_FILE_EXT", "'." . implode("', '.", array_keys($allowedexts)) . "'"),
					"errorcode" => "invalid_file_ext"
				);
			}
			else
			{
				// For chunked file uploads, get the current filename and starting position from the incoming headers.
				$name = self::GetChunkFilename();
				if ($name !== false)
				{
					$startpos = self::GetFileStartPosition();

					$name = substr($name, 0, -(strlen($files[0]["ext"]) + 1));

					if (isset($options["filename_callback"]) && is_callable($options["filename_callback"]))  $filename = call_user_func_array($options["filename_callback"], array($name, strtolower($files[0]["ext"]), $files[0]));
					else if (isset($options["filename"]))  $filename = str_replace(array("{name}", "{ext}"), array($name, strtolower($files[0]["ext"])), $options["filename"]);
					else  $filename = false;

					if (!is_string($filename))  $result = array("success" => false, "error" => self::FFTranslate("INVALID_FILENAME"), "errorcode" => "invalid_filename");
					else if (isset($options["limit"]) && $options["limit"] > -1 && $startpos + filesize($files[0]["file"]) > $options["limit"])  $result = array("success" => false, "error" => self::FFTranslate("FILE_TOO_LARGE"), "errorcode" => "file_too_large");
					else
					{
						if (file_exists($filename) && $startpos === filesize($filename))  $fp = @fopen($filename, "ab");
						else
						{
							$fp = @fopen($filename, ($startpos > 0 && file_exists($filename) ? "r+b" : "wb"));
							if ($fp !== false)  @fseek($fp, $startpos, SEEK_SET);
						}

						$fp2 = @fopen($files[0]["file"], "rb");

						if ($fp === false)  $result = array("success" => false, "error" => self::FFTranslate("OPEN_WRITE_FAILED"), "errorcode" => "open_failed", "info" => $filename);
						else if ($fp2 === false)  $result = array("success" => false, "error" => self::FFTranslate("OPEN_READ_FAILED"), "errorcode" => "open_failed", "info" => $files[0]["file"]);
						else
						{
							do
							{
								$data2 = @fread($fp2, 1048576);
								if ($data2 == "")  break;

								@fwrite($fp, $data2);
							} while (1);

							fclose($fp2);
							fclose($fp);

							$result = array(
								"success" => true
							);
						}
					}
				}
				else
				{
					$name = substr($files[0]["name"], 0, -(strlen($files[0]["ext"]) + 1));

					if (isset($options["filename_callback"]) && is_callable($options["filename_callback"]))  $filename = call_user_func_array($options["filename_callback"], array($name, strtolower($files[0]["ext"]), $files[0]));
					else if (isset($options["filename"]))  $filename = str_replace(array("{name}", "{ext}"), array($name, strtolower($files[0]["ext"])), $options["filename"]);
					else  $filename = false;

					if (!is_string($filename))  $result = array("success" => false, "error" => self::FFTranslate("INVALID_FILENAME"), "errorcode" => "invalid_filename");
					else if (isset($options["limit"]) && $options["limit"] > -1 && filesize($files[0]["file"]) > $options["limit"])  $result = array("success" => false, "error" => self::FFTranslate("FILE_TOO_LARGE"), "errorcode" => "file_too_large");
					else
					{
						@copy($files[0]["file"], $filename);

						$result = array(
							"success" => true
						);
					}
				}
			}

			if ($result["success"] && isset($options["result_callback"]) && is_callable($options["result_callback"]))  call_user_func_array($options["result_callback"], array(&$result, $filename, $name, strtolower($files[0]["ext"]), $files[0], (isset($options["result_callback_opts"]) ? $options["result_callback_opts"] : false)));

			if (isset($options["return_result"]) && $options["return_result"])  return $result;

			echo json_encode($result, JSON_UNESCAPED_SLASHES);
			exit();
		}

		public static function FFTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			// Define default translations
			$translations = [];
			$translations['LANG']					= "English";
			$translations['UPLOAD_ERR_INI_SIZE']	= "The uploaded file exceeds the 'upload_max_filesize' directive in 'php.ini'.";
			$translations['UPLOAD_ERR_FORM_SIZE']	= "The uploaded file exceeds the 'MAX_FILE_SIZE' directive that was specified in the submitted form.";
			$translations['UPLOAD_ERR_PARTIAL']		= "The uploaded file was only partially uploaded.";
			$translations['UPLOAD_ERR_NO_FILE']		= "No file was uploaded.";
			$translations['UPLOAD_ERR_NO_TMP_DIR']	= "The configured temporary folder on the server is missing.";
			$translations['UPLOAD_ERR_CANT_WRITE']	= "Unable to write the temporary file to disk.  The server is out of disk space, incorrectly configured, or experiencing hardware issues.";
			$translations['UPLOAD_ERR_EXTENSION']	= "A PHP extension stopped the upload.";
			$translations['UPLOAD_ERR_UNKNOWN']		= "An unknown error occurred.";
			$translations['INVALID_INPUT_FILENAME']	= "The specified input filename was not uploaded to this server.";
			$translations['BAD_INPUT']				= "File data was submitted but is missing.";
			$translations['INVALID_FILE_EXT']		= "Invalid file extension.  Must be one of %s.";
			$translations['INVALID_FILENAME']		= "The server did not set a valid filename.";
			$translations['FILE_TOO_LARGE']			= "The server file size limit was exceeded.";
			$translations['OPEN_WRITE_FAILED']		= "Unable to open a required file for writing.";
			$translations['OPEN_READ_FAILED']		= "Unable to open a required file for reading.";

			// If a translate function is defined as below use it...
			// define("CS_TRANSLATE_FUNC", "MyTranslateFunction");
			if (defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC))
			{
				// CS_TRANSLATE_FUNC is defined and exists, check for new format.
				// A try to translate "LANG" should not give back "LANG" in new format mode.
				if ((call_user_func_array(CS_TRANSLATE_FUNC, array("LANG")) === "LANG"))
				{
					// CS_TRANSLATE_FUNC function exists but seems to use 
					// the old format. To use the new format, a translation 
					// variable LANG must have a content different from LANG.
					// To keep backward compatibility I convert the strings in 
					// $args back from variable to the old text format before proceeding...
					foreach ($args as $key => $value)
					{
						$args = str_replace("$value",$translations["$value"],$args);
					}
				}
			}
			else
			{
				// CS_TRANSLATE_FUNC function doesn't exists.
				// To keep backward compatibility I convert the strings in 
				// $args back from variable to the old text format before proceeding...
				foreach ($args as $key => $value)
				{
					$args = str_replace("$value",$translations["$value"],$args);
				}
			}
			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>