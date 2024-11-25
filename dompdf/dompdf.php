<?php
    static function resolve_url($url, $protocol, $host, $base_path, Options $options)
    {
        $tempfile = null;
        $resolved_url = null;
        $type = null;
        $message = null;
        
        try {
            $full_url = Helpers::build_url($protocol, $host, $base_path, $url);

            if ($full_url === null) {
                throw new ImageException("Unable to parse image URL $url.", E_WARNING);
            }

            $parsed_url = Helpers::explode_url($full_url);
            $protocol = strtolower($parsed_url["protocol"]);
            $is_data_uri = strpos($protocol, "data:") === 0;
            
            if (!$is_data_uri) {
                $allowed_protocols = $options->getAllowedProtocols();
                if (!array_key_exists($protocol, $allowed_protocols)) {
                    throw new ImageException("Permission denied on $url. The communication protocol is not supported.", E_WARNING);
                }
                foreach ($allowed_protocols[$protocol]["rules"] as $rule) {
                    [$result, $message] = $rule($full_url);
                    if (!$result) {
                        throw new ImageException("Error loading $url: $message", E_WARNING);
                    }
                }
            }

            if ($protocol === "file://") {
                $resolved_url = $full_url;
            } elseif (isset(self::$_cache[$full_url])) {
                $resolved_url = self::$_cache[$full_url];
            } else {
                $tmp_dir = $options->getTempDir();
                if (($resolved_url = @tempnam($tmp_dir, "ca_dompdf_img_")) === false) {
                    throw new ImageException("Unable to create temporary image in " . $tmp_dir, E_WARNING);
                }
                $tempfile = $resolved_url;

                $image = null;
                if ($is_data_uri) {
                    if (($parsed_data_uri = Helpers::parse_data_uri($url)) !== false) {
                        $image = $parsed_data_uri["data"];
                    }
                } else {
                    list($image, $http_response_header) = Helpers::getFileContent($full_url, $options->getHttpContext());
                }

                // Image not found or invalid
                if ($image === null) {
                    $msg = ($is_data_uri ? "Data-URI could not be parsed" : "Image not found");
                    throw new ImageException($msg, E_WARNING);
                }

                if (@file_put_contents($resolved_url, $image) === false) {
                    throw new ImageException("Unable to create temporary image in " . $tmp_dir, E_WARNING);
                }

                self::$_cache[$full_url] = $resolved_url;
            }

            // Check if the local file is readable
            if (!is_readable($resolved_url) || !filesize($resolved_url)) {
                throw new ImageException("Image not readable or empty", E_WARNING);
            }

            list($width, $height, $type) = Helpers::dompdf_getimagesize($resolved_url, $options->getHttpContext());

            if (($width && $height && in_array($type, ["gif", "png", "jpeg", "bmp", "svg","webp"], true)) === false) {
                throw new ImageException("Image type unknown", E_WARNING);
            }

            if ($type === "svg") {
                $parser = xml_parser_create("utf-8");
                xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
                xml_set_element_handler(
                    $parser,
                    function ($parser, $name, $attributes) use ($options, $parsed_url, $full_url) {
                        if (strtolower($name) === "image") {
                            $attributes = array_change_key_case($attributes, CASE_LOWER);
                            $urls = [];
                            $urls[] = $attributes["xlink:href"] ?? "";
                            $urls[] = $attributes["href"] ?? "";
                            foreach ($urls as $url) {
                                if (!empty($url)) {
                                    $inner_full_url = Helpers::build_url($parsed_url["protocol"], $parsed_url["host"], $parsed_url["path"], $url);
                                    if ($inner_full_url === $full_url) {
                                        throw new ImageException("SVG self-reference is not allowed", E_WARNING);
                                    }
                                    [$resolved_url, $type, $message] = self::resolve_url($url, $parsed_url["protocol"], $parsed_url["host"], $parsed_url["path"], $options);
                                    if (!empty($message)) {
                                        throw new ImageException("This SVG document references a restricted resource. $message", E_WARNING);
                                    }
                                }
                            }
                        }
                    },
                    false
                );
        
                if (($fp = fopen($resolved_url, "r")) !== false) {
                    while ($line = fread($fp, 8192)) {
                        xml_parse($parser, $line, false);
                    }
                    fclose($fp);
                    xml_parse($parser, "", true);
                }
                xml_parser_free($parser);
            }
        } catch (ImageException $e) {
            if ($tempfile) {
                unlink($tempfile);
            }
            $resolved_url = self::$broken_image;
            list($width, $height, $type) = Helpers::dompdf_getimagesize($resolved_url, $options->getHttpContext());
            $message = self::$error_message;
            Helpers::record_warnings($e->getCode(), $e->getMessage() . " \n $url", $e->getFile(), $e->getLine());
            self::$_cache[$full_url] = $resolved_url;
        }

        return [$resolved_url, $type, $message];
    }

    /**
     * open the font file and return a php structure containing it.
     * first check if this one has been done before and saved in a form more suited to php
     * note that if a php serialized version does not exist it will try and make one, but will
     * require write access to the directory to do it... it is MUCH faster to have these serialized
     * files.
     *
     * @param $font
     */
    private function openFont($font)
    {
        // assume that $font contains the path and file but not the extension
        $name = basename($font);
        $dir = dirname($font);

        $fontcache = $this->fontcache;
        if ($fontcache == '') {
            $fontcache = $dir;
        }

        //$name       filename without folder and extension of font metrics
        //$dir        folder of font metrics
        //$fontcache  folder of runtime created php serialized version of font metrics.
        //            If this is not given, the same folder as the font metrics will be used.
        //            Storing and reusing serialized versions improves speed much

        $this->addMessage("openFont: $font - $name");

        if (!$this->isUnicode || in_array(mb_strtolower(basename($name)), self::$coreFonts)) {
            $metrics_name = "$name.afm";
        } else {
            $metrics_name = "$name.ufm";
        }

        $cache_name = "$metrics_name.json";
        $this->addMessage("metrics: $metrics_name, cache: $cache_name");

        if (file_exists($fontcache . '/' . $cache_name)) {
            $this->addMessage("openFont: json metrics file exists $fontcache/$cache_name");
            $cached_font_info = json_decode(file_get_contents($fontcache . '/' . $cache_name), true);
            if (!isset($cached_font_info['_version_']) || $cached_font_info['_version_'] != $this->fontcacheVersion) {
                $this->addMessage('openFont: font cache is out of date, regenerating');
            } else {
                $this->fonts[$font] = $cached_font_info;
            }
        }

        if (!isset($this->fonts[$font]) && file_exists("$dir/$metrics_name")) {
            // then rebuild the php_<font>.afm file from the <font>.afm file
            $this->addMessage("openFont: build php file from $dir/$metrics_name");
            $data = [];

            // 20 => 'space'
            $data['codeToName'] = [];

            // Since we're not going to enable Unicode for the core fonts we need to use a font-based
            // setting for Unicode support rather than a global setting.
            $data['isUnicode'] = (strtolower(substr($metrics_name, -3)) !== 'afm');

            $cidtogid = '';
            if ($data['isUnicode']) {
                $cidtogid = str_pad('', 256 * 256 * 2, "\x00");
            }

            $file = file("$dir/$metrics_name");

            foreach ($file as $rowA) {
                $row = trim($rowA);
                $pos = strpos($row, ' ');

                if ($pos) {
                    // then there must be some keyword
                    $key = substr($row, 0, $pos);
                    switch ($key) {
                        case 'FontName':
                        case 'FullName':
                        case 'FamilyName':
                        case 'PostScriptName':
                        case 'Weight':
                        case 'ItalicAngle':
                        case 'IsFixedPitch':
                        case 'CharacterSet':
                        case 'UnderlinePosition':
                        case 'UnderlineThickness':
                        case 'Version':
                        case 'EncodingScheme':
                        case 'CapHeight':
                        case 'XHeight':
                        case 'Ascender':
                        case 'Descender':
                        case 'StdHW':
                        case 'StdVW':
                        case 'StartCharMetrics':
                        case 'FontHeightOffset': // OAR - Added so we can offset the height calculation of a Windows font.  Otherwise it's too big.
                            $data[$key] = trim(substr($row, $pos));
                            break;

                        case 'FontBBox':
                            $data[$key] = explode(' ', trim(substr($row, $pos)));
                            break;

                        //C 39 ; WX 222 ; N quoteright ; B 53 463 157 718 ;
                        case 'C': // Found in AFM files
                            $bits = explode(';', trim($row));
                            $dtmp = ['C' => null, 'N' => null, 'WX' => null, 'B' => []];

                            foreach ($bits as $bit) {
                                $bits2 = explode(' ', trim($bit));
                                if (mb_strlen($bits2[0], '8bit') == 0) {
                                    continue;
                                }

                                if (count($bits2) > 2) {
                                    $dtmp[$bits2[0]] = [];
                                    for ($i = 1; $i < count($bits2); $i++) {
                                        $dtmp[$bits2[0]][] = $bits2[$i];
                                    }
                                } else {
                                    if (count($bits2) == 2) {
                                        $dtmp[$bits2[0]] = $bits2[1];
                                    }
                                }
                            }

                            $c = (int)$dtmp['C'];
                            $n = $dtmp['N'];
                            $width = floatval($dtmp['WX']);

                            if ($c >= 0) {
                                if (!ctype_xdigit($n) || $c != hexdec($n)) {
                                    $data['codeToName'][$c] = $n;
                                }
                                $data['C'][$c] = $width;
                            } elseif (isset($n)) {
                                $data['C'][$n] = $width;
                            }

                            if (!isset($data['MissingWidth']) && $c === -1 && $n === '.notdef') {
                                $data['MissingWidth'] = $width;
                            }

                            break;

                        // U 827 ; WX 0 ; N squaresubnosp ; G 675 ;
                        case 'U': // Found in UFM files
                            if (!$data['isUnicode']) {
                                break;
                            }

                            $bits = explode(';', trim($row));
                            $dtmp = ['G' => null, 'N' => null, 'U' => null, 'WX' => null];

                            foreach ($bits as $bit) {
                                $bits2 = explode(' ', trim($bit));
                                if (mb_strlen($bits2[0], '8bit') === 0) {
                                    continue;
                                }

                                if (count($bits2) > 2) {
                                    $dtmp[$bits2[0]] = [];
                                    for ($i = 1; $i < count($bits2); $i++) {
                                        $dtmp[$bits2[0]][] = $bits2[$i];
                                    }
                                } else {
                                    if (count($bits2) == 2) {
                                        $dtmp[$bits2[0]] = $bits2[1];
                                    }
                                }
                            }

                            $c = (int)$dtmp['U'];
                            $n = $dtmp['N'];
                            $glyph = $dtmp['G'];
                            $width = floatval($dtmp['WX']);

                            if ($c >= 0) {
                                // Set values in CID to GID map
                                if ($c >= 0 && $c < 0xFFFF && $glyph) {
                                    $cidtogid[$c * 2] = chr($glyph >> 8);
                                    $cidtogid[$c * 2 + 1] = chr($glyph & 0xFF);
                                }

                                if (!ctype_xdigit($n) || $c != hexdec($n)) {
                                    $data['codeToName'][$c] = $n;
                                }
                                $data['C'][$c] = $width;
                            } elseif (isset($n)) {
                                $data['C'][$n] = $width;
                            }

                            if (!isset($data['MissingWidth']) && $c === -1 && $n === '.notdef') {
                                $data['MissingWidth'] = $width;
                            }

                            break;

                        case 'KPX':
                            break; // don't include them as they are not used yet
                            //KPX Adieresis yacute -40
                            /*$bits = explode(' ', trim($row));
                            $data['KPX'][$bits[1]][$bits[2]] = $bits[3];
                            break;*/
                    }
                }
            }
        }
    }

},