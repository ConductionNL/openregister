<?php
/*
    This file is part of SAPP

    Simple and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
    Copyright (C) 2020 - Carlos de Alfonso (caralla76@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace ddn\sapp;

use ddn\sapp\PDFBaseDoc;
use ddn\sapp\PDFBaseObject;
use ddn\sapp\PDFSignatureObject;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueReference;
use ddn\sapp\pdfvalue\PDFValueType;
use ddn\sapp\pdfvalue\PDFValueSimple;
use ddn\sapp\pdfvalue\PDFValueHexString;
use ddn\sapp\pdfvalue\PDFValueString;
use ddn\sapp\helpers\CMS;
use ddn\sapp\helpers\x509;
use ddn\sapp\helpers\asn1;
use ddn\sapp\helpers\Buffer;
use ddn\sapp\helpers\UUID;
use ddn\sapp\helpers\DependencyTreeObject;
use const ddn\sapp\helpers\BLACKLIST;
use function ddn\sapp\helpers\references_in_object;

use function ddn\sapp\helpers\get_random_string;
use function ddn\sapp\helpers\p_debug;
use function ddn\sapp\helpers\p_debug_var;
use function ddn\sapp\helpers\p_error;
use function ddn\sapp\helpers\p_warning;
use function ddn\sapp\helpers\_add_image;
use function ddn\sapp\helpers\timestamp_to_pdfdatestring;

// Loading the functions
use ddn\sapp\helpers\LoadHelpers;
if (!defined("ddn\\sapp\\helpers\\LoadHelpers"))
    new LoadHelpers;

// TODO: move the signature of documents to a new class (i.e. PDFDocSignable)
// TODO: create a new class "PDFDocIncremental"

class PDFDoc extends Buffer {

    // The PDF version of the parsed file
    protected $_pdf_objects = [];
    protected $_pdf_version_string = null;
    protected $_pdf_trailer_object = null;
    protected $_xref_position = 0;
    protected $_xref_table = [];
    protected $_max_oid = 0;
    protected $_buffer = "";
    protected $_backup_state = [];
    protected $_certificate = null;
    protected $_signature_ltv_data = null;
    protected $_signature_tsa = null;
    protected $_appearance = null;
    protected $_xref_table_version;
    protected $_revisions;
    protected $_metadata_name = null;
    protected $_metadata_reason = null;
    protected $_metadata_location = null;
    protected $_metadata_contact_info = null;

    // Array of pages ordered by appearance in the final doc (i.e. index 0 is the first page rendered; index 1 is the second page rendered, etc.)
    // Each entry is an array with the following fields:
    //  - id: the id in the document (oid); can be translated into <id> 0 R for references
    //  - info: an array with information about the page
    //      - size: the size of the page
    protected $_pages_info = [];

    /**
     * Per-page record of whether the Helvetica fallback font resource
     * was already injected into the page's /Resources/Font, used by
     * replace_text_in_document() when the active subset font can't
     * encode the placeholder. Keyed by page OID; value is the
     * injected resource name with leading slash (e.g. '/F-fb-anonym').
     *
     * Lifetime invariant: persists for the lifetime of this PDFDoc
     * instance. Two consecutive replace_text_in_document() calls on
     * the same instance therefore reuse the same Helvetica font
     * object and resource name (D6 idempotency contract). If the
     * caller manually reloads page objects after the first call, the
     * cache will be out of sync with the new objects — callers SHOULD
     * NOT mix manual page-object mutation with this cache.
     *
     * @var array<int, string>
     */
    protected $_fallback_font_injected = [];

    /**
     * Cached OID of the Helvetica fallback font object once created
     * lazily inside injectFallbackFontResource(). Shared across all
     * pages that need the fallback in this PDFDoc instance.
     *
     * @var int|null
     */
    protected $_fallback_font_oid = null;

    // Gets a new oid for a new object
    protected function get_new_oid() {
        $this->_max_oid++;
        return $this->_max_oid;
    }

    /**
     * Retrieve the number of pages in the document (not considered those pages that could be added by the user using this object or derived ones)
     * @return pagecount number of pages in the original document
     */
    public function get_page_count() {
        return count($this->_pages_info);
    }

    /**
     * Function that backups the current objects with the objective of making temporary modifications, and to restore
     *   the state using function "pop_state". Many states can be stored, and they will be retrieved in reverse order
     *   using pop_state
     */
    public function push_state() {
        $cloned_objects = [];
        foreach ($this->_pdf_objects as $oid => $object) {
            $cloned_objects[$oid] = clone $object;
        }
        array_push($this->_backup_state, [ 'max_oid' => $this->_max_oid, 'pdf_objects' => $cloned_objects ]);
    }

    /**
     * Function that retrieves an stored state by means of function "push_state"
     * @return restored true if a previous state was restored; false if there was no stored state
     */
    public function pop_state() {
        if (count($this->_backup_state) > 0) {
            $state = array_pop($this->_backup_state);
            $this->_max_oid = $state['max_oid'];
            $this->_pdf_objects = $state['pdf_objects'];
            return true;
        }
        return false;
    }

    /**
     * The function parses a document from a string: analyzes the structure and obtains and object
     *   of type PDFDoc (if possible), or false, if an error happens.
     * @param buffer a string that contains the file to analyze
     * @param depth the number of previous versions to consider; if null, will consider any version;
     *              otherwise only the object ids from the latest $depth versions will be considered
     *              (if it is an incremental updated document)
     */
    public static function from_string($buffer, $depth = null) {
        $structure = PDFUtilFnc::acquire_structure($buffer, $depth);
        if ($structure === false)
            return false;

        $trailer = $structure["trailer"];
        $version = $structure["version"];
        $xref_table = $structure["xref"];
        $xref_position = $structure["xrefposition"];
        $revisions = $structure["revisions"];

        $pdfdoc = new PDFDoc();
        $pdfdoc->_pdf_version_string = $version;
        $pdfdoc->_pdf_trailer_object = $trailer;
        $pdfdoc->_xref_position = $xref_position;
        $pdfdoc->_xref_table = $xref_table;
        $pdfdoc->_xref_table_version = $structure["xrefversion"];
        $pdfdoc->_revisions = $revisions;
        $pdfdoc->_buffer = $buffer;

        if ($trailer !== false)
            if ($trailer['Encrypt'] !== false)
                // TODO: include encryption (maybe borrowing some code: http://www.fpdf.org/en/script/script37.php)
                p_error("encrypted documents are not fully supported; maybe you cannot get the expected results");

        $oids = array_keys($xref_table);
        sort($oids);
        $pdfdoc->_max_oid = array_pop($oids);

        if ($trailer === false)
            p_warning("invalid trailer object");
        else
            $pdfdoc->_acquire_pages_info();

        return $pdfdoc;
    }

    public function get_revision($rev_i) {
        if ($rev_i === null)
            $rev_i = count($this->_revisions) - 1;
        if ($rev_i < 0)
            $rev_i = count($this->_revisions) + $rev_i - 1;

        return substr($this->_buffer, 0, $this->_revisions[$rev_i]);
    }

    /**
     * Function that builds the object list from the xref table
     */
    public function build_objects_from_xref() {
        foreach ($this->_xref_table as $oid => $obj) {
            $obj = $this->get_object($oid);
            $this->add_object($obj);
        }
    }

    /**
     * This function creates an interator over the objects of the document, and makes use of function "get_object".
     *   This mechanism enables to walk over any object, either they are new ones or they were in the original doc.
     *   Enables:
     *         foreach ($doc->get_object_iterator() as $oid => obj) { ... }
     * @param allobjects the iterator obtains any possible object, according to the oids; otherwise, only will return the
     *      objects that appear in the current version of the xref
     * @return oid=>obj the objects
     */
    public function get_object_iterator($allobjects = false) {
        if ($allobjects === true) {
            for ($i = 0; $i <= $this->_max_oid; $i++) {
                yield $i => $this->get_object($i);
            }
        } else {
            foreach ($this->_xref_table as $oid => $offset) {
                if ($offset === null) continue;

                $o = $this->get_object($oid);
                if ($o === false) continue;

                yield $oid => $o;
            }
        }
    }

    /**
     * This function checks whether the passed object is a reference or not, and in case that
     *   it is a reference, it returns the referenced object; otherwise it return the object itself
     * @param reference the reference value to obtain
     * @return obj it reference can be interpreted as a reference, the referenced object; otherwise, the object itself.
     *   If the passed value is an array of references, it will return false
     */
    public function get_indirect_object( $reference ) {
        $object_id = $reference->get_object_referenced();
        if ($object_id !== false) {
            if (is_array($object_id))
                return false;
            return $this->get_object($object_id);
        }
        return $reference;
    }

    /**
     * Resolve a value that may be an indirect reference to its target's value.
     *
     * Handles BOTH the `PDFValueReference` form and the `PDFValueSimple "N G R"`
     * form: some producers emit `/Resources` or `/Font` as a bare "6 0 R" token
     * parsed as a simple value rather than a reference object. The previous
     * `is_a(..., PDFValueReference)` gates missed that form, leaving the dict
     * unresolved (`get_keys()` === false) so the page's fonts were never
     * collected — which left accented text (ë/ï/ü) resolved as raw WinAnsi
     * bytes instead of UTF-8, so accented needles never matched. Returns the
     * input unchanged when it is not an indirect reference.
     *
     * @param mixed $value A PDF value, possibly an indirect reference.
     *
     * @return mixed The referenced dictionary value, or $value unchanged.
     */
    private function resolveIndirectValue( $value ) {
        if (!is_object($value) || !method_exists($value, 'get_object_referenced')) {
            return $value;
        }
        $ref = $value->get_object_referenced();
        if ($ref === false || is_array($ref)) {
            return $value;
        }
        $obj = $this->get_indirect_object($value);
        if (is_object($obj) && method_exists($obj, 'get_value')) {
            return $obj->get_value();
        }
        return $value;
    }

    /**
     * Obtains an object from the document, usign the oid in the PDF document.
     * @param oid the oid of the object that is being retrieved
     * @param original if true and the object has been overwritten in this document, the object
     *                 retrieved will be the original one. Setting to false will retrieve the
     *                 more recent object
     * @return obj the object retrieved (or false if not found)
     */
    public function get_object($oid, $original_version = false) {
        if ($original_version === true) {
            // Prioritizing the original version
            $object = PDFUtilFnc::find_object($this->_buffer, $this->_xref_table, $oid);
            if ($object === false)
                $object = $this->_pdf_objects[$oid]??false;

        } else {
            // Prioritizing the new versions
            $object = $this->_pdf_objects[$oid]??false;
            if ($object === false)
                $object = PDFUtilFnc::find_object($this->_buffer, $this->_xref_table, $oid);
        }

        return $object;
    }

    /**
     * Function that sets the appearance of the signature (if the document is to be signed). At this time, it is possible to set
     *   the page in which the signature will appear, the rectangle, and an image that will be shown in the signature form.
     * @param page the page (zero based) in which the signature will appear
     * @param rect the rectangle (in page-based coordinates) where the signature will appear in that page
     * @param imagefilename an image file name (or an image in a buffer, with symbol '@' prepended) that will be put inside the rect
     * @param string|null $name the name of the signature (if not set, a random name will be used)
     */
    public function set_signature_appearance($page_to_appear = 0, $rect_to_appear = [0, 0, 0, 0], $imagefilename = null, $name = null) {
        $this->_appearance = [
            "page" => $page_to_appear,
            "rect" => $rect_to_appear,
            "image" => $imagefilename,
            "name" => $name,
        ];
    }

    /**
     * Removes the settings of signature appearance (i.e. no signature will appear in the document)
     */
    public function clear_signature_appearance() {
        $this->_appearance = null;
    }

    /**
     * Removes the certificate for the signature (i.e. the document will not be signed)
     */
    public function clear_signature_certificate() {
        $this->_certificate = null;
    }

    /**
     * Function that stores the certificate to use, when signing the document
     * @param certfile a file that contains a user certificate in pkcs12 format,
     *                 or an array [ 'cert' => <cert.pem>, 'pkey' => <key.pem>, 'extracerts' => <extracerts.pem|null> ]
     *                 that would be the output of openssl_pkcs12_read
     * @param password the password to read the private key
     * @return valid true if the certificate can be used to sign the document, false otherwise
     */
    public function set_signature_certificate($certfile, $certpass = null) {
        // First we read the certificate
        if (is_array($certfile)) {
            $certificate = $certfile;
            $certificate["pkey"] = [$certificate["pkey"], $certpass];

            // If a password is provided, we'll try to decode the private key
            if (openssl_pkey_get_private($certificate["pkey"]) === false)
                return p_error("invalid private key");
            if (! openssl_x509_check_private_key($certificate["cert"], $certificate["pkey"]))
                return p_error("private key doesn't corresponds to certificate");

            if (is_string($certificate['extracerts'] ?? null)) {
                $certificate['extracerts'] = array_filter(explode("-----END CERTIFICATE-----\n", $certificate['extracerts']));
                foreach ($certificate['extracerts'] as &$extracerts)
                    $extracerts = $extracerts . "-----END CERTIFICATE-----\n";
            }
        } else {
            $certfilecontent = file_get_contents($certfile);
            if ($certfilecontent === false)
                return p_error("could not read file $certfile");
            if (openssl_pkcs12_read($certfilecontent, $certificate, $certpass) === false)
                return p_error("could not get the certificates from file $certfile");
        }

        // Store the certificate
        $this->_certificate = $certificate;

        return true;
    }

    /**
     * Function that stores the ltv configuration to use, when signing the document
     * @param $ocspURI  OCSP Url to validate cert file
     * @param $crlURIorFILE Crl filename/url to validate cert
     * @param $issuerURIorFILE issuer filename/url
     */
    public function set_ltv($ocspURI=null, $crlURIorFILE=null, $issuerURIorFILE=null) {
        $this->_signature_ltv_data['ocspURI'] = $ocspURI;
        $this->_signature_ltv_data['crlURIorFILE'] = $crlURIorFILE;
        $this->_signature_ltv_data['issuerURIorFILE'] = $issuerURIorFILE;
    }

    /**
     * Function that stores the tsa configuration to use, when signing the document
     * @param $tsaurl  Link to tsa service
     * @param $tsauser the user for tsa service
     * @param $tsapass the password for tsa service
     */
    public function set_tsa($tsa, $tsauser = null, $tsapass = null) {
        $this->_signature_tsa['host'] = $tsa;
        if ($tsauser && $tsapass) {
            $this->_signature_tsa['user'] = $tsauser;
            $this->_signature_tsa['password'] = $tsapass;
        }
    }

    /**
     * Function to set the metadata properties for the certificate options
     * @param $name
     * @param $reason
     * @param $location
     * @param $contact
     * @return void
     */
    public function set_metadata_props($name = null, $reason = null, $location = null, $contact = null)
    {
        $this->_metadata_name = self::toUTF16Hex($name);
        $this->_metadata_reason = self::toUTF16Hex($reason);
        $this->_metadata_location = self::toUTF16Hex($location);
        $this->_metadata_contact_info = self::toUTF16Hex($contact);
    }

    // Convert string to UTF-16 Hexadecimal 
    private static function toUTF16Hex($string) {
        $string = bin2hex(mb_convert_encoding($string, 'UTF-16BE'));

        // Add BOM
        return 'FEFF' .$string;
    }
    /**
     * Function that creates and updates the PDF objects needed to sign the document. The workflow for a signature is:
     * - create a signature object
     * - create an annotation object whose value is the signature object
     * - create a form object (along with other objects) that will hold the appearance of the annotation object
     * - modify the root object to make acroform point to the annotation object
     * - modify the page object to make the annotations of that page include the annotation object
     *
     * > If the appearance is not set, the image will not appear, and the signature object will be invisible.
     * > If the certificate is not set, the signature created will be a placeholder (that acrobat will able to sign)
     *
     *      LIMITATIONS: one document can be signed once at a time; if wanted more signatures, then chain the documents:
     *      $o1->set_signature_certificate(...);
     *      $o2 = PDFDoc::fromstring($o1->to_pdf_file_s);
     *      $o2->set_signature_certificate(...);
     *      $o2->to_pdf_file_s();
     *
     * @return signature a signature object, or null if the document is not signed; false if an error happens
     */
    protected function _generate_signature_in_document() {
        $imagefilename = null;
        $recttoappear = [ 0, 0, 0, 0];
        $pagetoappear = 0;

        if ($this->_appearance !== null) {
            $imagefilename = $this->_appearance["image"];
            $recttoappear = $this->_appearance["rect"];
            $pagetoappear = $this->_appearance["page"];
        }

        // First of all, we are searching for the root object (which should be in the trailer)
        $root = $this->_pdf_trailer_object["Root"];

        if (($root === false) || (($root = $root->get_object_referenced()) === false))
            return p_error("could not find the root object from the trailer");

        $root_obj = $this->get_object($root);
        if ($root_obj === false)
            return p_error("invalid root object");

        // Now the object corresponding to the page number in which to appear
        $page_obj = $this->get_page($pagetoappear);
        if ($page_obj === false)
            return p_error("invalid page");

        // The objects to update
        $updated_objects = [ ];

        // Add the annotation to the page
        if (!isset($page_obj["Annots"]))
            $page_obj["Annots"] = new PDFValueList();

        $annots = &$page_obj["Annots"];
        $page_rotation = $page_obj["Rotate"]??new PDFValueSimple(0);

        if ((($referenced = $annots->get_object_referenced()) !== false) && (!is_array($referenced))) {
            // It is an indirect object, so we need to update that object
            $newannots = $this->create_object(
                $this->get_object($referenced)->get_value()
            );
        } else {
            $newannots = $this->create_object(
                new PDFValueList()
            );
            $newannots->push($annots);
        }

        // Create the annotation object, annotate the offset and append the object
        $annotation_object = $this->create_object([
                "Type" => "/Annot",
                "Subtype" => "/Widget",
                "FT" => "/Sig",
                "V" => new PDFValueString(""),
                "T" => new PDFValueString($this->_appearance['name'] ?? ('Signature' . get_random_string())),
                "P" => new PDFValueReference($page_obj->get_oid()),
                "Rect" => $recttoappear,
                "F" => 132  // TODO: check this value
            ]
        );

        // Prepare the signature object (we need references to it)
        $signature = null;
        if ($this->_certificate !== null) {
            // Perform signature test to get signature size to define __SIGNATURE_MAX_LENGTH
            p_debug("     ########## PERFORM SIGNATURE LENGTH CHECK ##########\n");
            $CMS = new helpers\CMS;
            $CMS->signature_data['signcert'] = $this->_certificate['cert'];
            $CMS->signature_data['extracerts'] = $this->_certificate['extracerts']??null;
            $CMS->signature_data['hashAlgorithm'] = 'sha256';
            $CMS->signature_data['privkey'] = $this->_certificate['pkey'];
            $CMS->signature_data['tsa'] = $this->_signature_tsa;
            $CMS->signature_data['ltv'] = $this->_signature_ltv_data;
            $res = $CMS->pkcs7_sign('0');
            $len = strlen($res);
            p_debug("     Signature Length is \"$len\" Bytes");
            p_debug("     ########## FINISHED SIGNATURE LENGTH CHECK #########\n\n");
            PDFSignatureObject::$__SIGNATURE_MAX_LENGTH = $len + 64;

            $signature = $this->create_object([], PDFSignatureObject::class, false);
            //$signature = new PDFSignatureObject([]);
            $signature->set_metadata($this->_metadata_name, $this->_metadata_reason, $this->_metadata_location, $this->_metadata_contact_info);
            $signature->set_certificate($this->_certificate);
            if($this->_signature_tsa !== null) {
              $signature->set_signature_tsa($this->_signature_tsa);
            }
            if($this->_signature_ltv_data !== null) {
              $signature->set_signature_ltv($this->_signature_ltv_data);
            }

            // Update the value to the annotation object
            $annotation_object["V"] = new PDFValueReference($signature->get_oid());
        }

        // If an image is provided, let's load it
        if ($imagefilename !== null) {
            // Signature with appearance, following the Adobe workflow:
            //   1. form
            //   2. layers /n0 (empty) and /n2
            // https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/acrobat_digital_signature_appearances_v9.pdf

            // Get the page height, to change the coordinates system (up to down)
            $pagesize = $this->get_page_size($pagetoappear);
            $pagesize = explode(" ", $pagesize[0]->val());
            $pagesize_h = floatval("" . $pagesize[3]) - floatval("" . $pagesize[1]);

            $bbox = [ 0, 0, $recttoappear[2] - $recttoappear[0], $recttoappear[3] - $recttoappear[1]];
            $form_object = $this->create_object([
                "BBox" => $bbox,
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Group" => [
                    'Type' => '/Group',
                    'S' => '/Transparency',
                    'CS' => '/DeviceRGB'
                ]
            ]);

            $container_form_object = $this->create_object([
                "BBox" => $bbox,
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Resources" => [ "XObject" => [
                    "n0" => new PDFValueSimple(""),
                    "n2" => new PDFValueSimple("")
                    ] ]
                ]);
            $container_form_object->set_stream("q 1 0 0 1 0 0 cm /n0 Do Q\nq 1 0 0 1 0 0 cm /n2 Do Q\n", false);

            $layer_n0 = $this->create_object([
                "BBox" => [ 0.0, 0.0, 100.0, 100.0 ],
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Resources" => new PDFValueObject()
            ]);

            // Add the same structure than Acrobat Reader
            $layer_n0->set_stream("% DSBlank" . __EOL, false);

            $layer_n2 = $this->create_object([
                "BBox" => $bbox,
                "Subtype" => "/Form",
                "Type" => "/XObject",
                "Resources" => new PDFValueObject()
            ]);

            $result = _add_image([$this, "create_object"], $imagefilename, $bbox[0], $bbox[1], $bbox[2], $bbox[3], $page_rotation->val());
            if ($result === false)
                return p_error("could not add the image");

            $layer_n2["Resources"] = $result["resources"];
            $layer_n2->set_stream($result['command'], false);

            $container_form_object["Resources"]["XObject"]["n0"] = new PDFValueReference($layer_n0->get_oid());
            $container_form_object["Resources"]["XObject"]["n2"] = new PDFValueReference($layer_n2->get_oid());

            $form_object['Resources'] = new PDFValueObject([
                "XObject" => [
                    "FRM" => new PDFValueReference($container_form_object->get_oid())
                ]
            ]);
            $form_object->set_stream("/FRM Do", false);

            // Set the signature appearance field to the form object
            $annotation_object["AP"] = [ "N" => new PDFValueReference($form_object->get_oid())];
            $annotation_object["Rect"] = [ $recttoappear[0], $pagesize_h - $recttoappear[1], $recttoappear[2], $pagesize_h - $recttoappear[3] ];
        }

        if (!$newannots->push(new PDFValueReference($annotation_object->get_oid())))
            return p_error("Could not update the page where the signature has to appear");

        $page_obj["Annots"] = new PDFValueReference($newannots->get_oid());
        array_push($updated_objects, $page_obj);

        // AcroForm may be an indirect object
        if (!isset($root_obj["AcroForm"]))
            $root_obj["AcroForm"] = new PDFValueObject();

        $acroform = &$root_obj["AcroForm"];
        if ((($referenced = $acroform->get_object_referenced()) !== false) && (!is_array($referenced))) {
            $acroform = $this->get_object($referenced);
            array_push($updated_objects, $acroform);
        } else {
            array_push($updated_objects, $root_obj);
        }

        // Add the annotation to the interactive form
        $acroform["SigFlags"] = 3;
        if (!isset($acroform['Fields']))
            $acroform['Fields'] = new PDFValueList();
        else {
            // Found some cases in which Fields is not a list, so we convert it into a list
            if (!($acroform['Fields'] instanceof PDFValueList)) {
                $val = $acroform['Fields'];
                $acroform['Fields'] = new PDFValueList();
                $acroform['Fields']->push($val);
            }
        }
        
        // Add the annotation object to the interactive form
        if (!$acroform['Fields']->push(new PDFValueReference($annotation_object->get_oid()))) {
            return p_error("could not create the signature field");
        }

        // Store the objects
        foreach ($updated_objects as &$object) {
            $this->add_object($object);
        }

        return $signature;
    }

    /**
     * Function that updates the modification date of the document. If modifies two parts: the "info" field of the trailer object
     *   and the xmp metadata field pointed by the root object.
     * @param date a DateTime object that contains the date to be set; null to set "now"
     * @return ok true if the date could be set; false otherwise
     */
    protected function update_mod_date(?\DateTime $date = null) {
        // First of all, we are searching for the root object (which should be in the trailer)
        $root = $this->_pdf_trailer_object["Root"];

        if (($root === false) || (($root = $root->get_object_referenced()) === false))
            return p_error("could not find the root object from the trailer");

        $root_obj = $this->get_object($root);
        if ($root_obj === false)
            return p_error("invalid root object");

        if ($date === null)
            $date = new \DateTime();

        // Update the xmp metadata if exists
        if (isset($root_obj["Metadata"])) {
            $metadata = $root_obj["Metadata"];
            if ((($referenced = $metadata->get_object_referenced()) !== false) && (!is_array($referenced))) {
                $metadata = $this->get_object($referenced);
                $metastream = $metadata->get_stream();
                $metastream = preg_replace('/<xmp:ModifyDate>([^<]*)<\/xmp:ModifyDate>/', '<xmp:ModifyDate>' . $date->format("c") . '</xmp:ModifyDate>', $metastream);
                $metastream = preg_replace('/<xmp:MetadataDate>([^<]*)<\/xmp:MetadataDate>/', '<xmp:MetadataDate>' . $date->format("c") . '</xmp:MetadataDate>', $metastream);
                $metastream = preg_replace('/<xmpMM:InstanceID>([^<]*)<\/xmpMM:InstanceID>/', '<xmpMM:InstanceID>uuid:' . UUID::v4() . '</xmpMM:InstanceID>', $metastream);
                $metadata->set_stream($metastream, false);
                $this->add_object($metadata);
            }
        }

        // Update the information object (not really needed)
        $info = $this->_pdf_trailer_object["Info"];
        if (($info === false) || (($info = $info->get_object_referenced()) === false))
            return p_error("could not find the info object from the trailer");

        $info_obj = $this->get_object($info);
        if ($info_obj === false)
            return p_error("invalid info object");

        $info_obj["ModDate"] = new PDFValueString(timestamp_to_pdfdatestring($date));
        $info_obj["Producer"] = new PDFValueString("Modificado con SAPP");
        $this->add_object($info_obj);
        return true;
    }

    /**
     * Function that gets the objects that have been read from the document
     * @return objects an array of objects, indexed by the oid of each object
     */
    public function get_objects() {
        return $this->_pdf_objects;
    }

    /**
     * Function that gets the version of the document. It will have the form
     *   PDF-1.x
     * @return version the PDF version
     */
    public function get_version() {
        return $this->_pdf_version_string;
    }

    /**
     * Function that sets the version for the document.
     * @param version the version of the PDF document (it shall have the form PDF-1.x)
     * @return correct true if the version had the proper form; false otherwise
     */
    public function set_version($version) {
        if (preg_match("/PDF-1.\[0-9\]/", $version) !== 1) {
            return false;
        }
        $this->_pdf_version_string = $version;
        return true;
    }

    /**
     * Function that creates a new PDFObject and stores it in the document object list, so that
     *   it is automatically managed by the document. The returned object can be modified and
     *   that modifications will be reflected in the document.
     * @param value the value that the object will contain
     * @return obj the PDFObject created
     */
    public function create_object($value = [], $class = "ddn\\sapp\\PDFObject", $autoadd = true): PDFObject {
        $o = new $class($this->get_new_oid(), $value);
        if ($autoadd === true)
            $this->add_object($o);
        return $o;
    }

    /**
     * Adds a pdf object to the document (overwrites the one with the same oid, if existed)
     * @param pdf_object the object to add to the document
     * @return true if the object was added; false otherwise (e.g. already exists an object of a greater generation)
     */
    public function add_object(PDFObject $pdf_object) {
        $oid = $pdf_object->get_oid();

        if (isset($this->_pdf_objects[$oid])) {
            if ($this->_pdf_objects[$oid]->get_generation() > $pdf_object->get_generation()) {
                return false;
            }
        }

        $this->_pdf_objects[$oid] = $pdf_object;

        // Update the maximum oid
        if ($oid > $this->_max_oid)
            $this->_max_oid = $oid;

        return true;
    }

    /**
     * This function generates all the contents of the file up to the xref entry.
     * @param rebuild whether to generate the xref with all the objects in the document (true) or
     *                consider only the new ones (false)
     * @return xref_data [ the text corresponding to the objects, array of offsets for each object ]
     */
    protected function _generate_content_to_xref($rebuild = false) {
        if ($rebuild === true) {
            $result  = new Buffer("%$this->_pdf_version_string" . __EOL);
        }  else {
            $result = new Buffer($this->_buffer);
        }

        // Need to calculate the objects offset
        $offsets = [];
        $offsets[0] = 0;

        // The objects
        $offset = $result->size();

        if ($rebuild === true) {
            for ($i = 0; $i <= $this->_max_oid; $i++) {
                if (($object = $this->get_object($i)) ===  false) continue;

                $result->data($object->to_pdf_entry());
                $offsets[$i] = $offset;
                $offset = $result->size();
            }
        } else {
            foreach ($this->_pdf_objects as $obj_id => $object) {
                $result->data($object->to_pdf_entry());
                $offsets[$obj_id] = $offset;
                $offset = $result->size();
            }
        }

        return [ $result, $offsets ];
    }

    /**
     * Replace text in this document's content streams, resolving through
     * each font's /ToUnicode CMap or implicit encoding before matching.
     *
     * Per `feat-tounicode-cmap` (PR #06): the matcher operates in text
     * space, not byte space. Tj operator operands are resolved through
     * the active font's encoding (either a parsed /ToUnicode CMap or
     * an implicit /WinAnsiEncoding-style table) and concatenated into a
     * Unicode view of the page text. Needles are searched against the
     * Unicode view; placeholders are encoded back to bytes through the
     * active font's forward map before splicing.
     *
     * Scope (this PR):
     *   - Tj operator only — TJ kerning-array flattening = PR #07.
     *   - The placeholder is emitted via the active font's forward
     *     map. When the font can't encode every placeholder character,
     *     the match is skipped and recorded as a `font_encoding_misses`
     *     diagnostic. The Helvetica fallback that recovers from this
     *     case is PR #08.
     *   - The needle's match must fit fully inside a single Tj operand
     *     (cross-Tj matches use TJ flattening = #07).
     *
     * Stream selection: `/FlateDecode`-encoded streams only (literal name;
     * the `/Fl` abbreviation and array-form chained filters are SKIPPED
     * — chained-filter dispatch lands in upstream PRs #01-#05). Any
     * decode that returns a non-string (filter chain failure → false /
     * legacy null) is skipped without throwing.
     *
     * **Serialisation contract**: after this method runs, callers MUST
     * use `to_pdf_file_b($rebuild = true)` (or `to_pdf_file_s(true)`) to
     * emit the modified document. The incremental path
     * (`$rebuild = false`) writes only the mutated objects with no
     * Catalog/Pages/Font references and produces an unopenable PDF —
     * the empty-`_pdf_objects` fast-path in `to_pdf_file_b` is
     * bypassed once this method has populated it.
     *
     * @param array<string, string> $substitutions Needle (UTF-8) → placeholder (UTF-8).
     *
     * @phpstan-type ReplaceTextStats array{
     *   streams_scanned: int,
     *   content_streams_scanned: int,
     *   streams_modified: int,
     *   replacements_per_needle: array<string, int>,
     *   unmatched_needles: list<string>,
     *   font_encoding_misses: array<int, array<string, string>>,
     *   cid_split_mismatch: array<int, array<string, int>>,
     *   encoding_dict_unhandled: array<int, array<string, string>>,
     *   contents_array_pages: array<int, true>,
     *   tj_arrays_modified: int,
     *   subset_font_fallbacks_used: int,
     *   rejected_substitutions: array<string, string>,
     * }
     *
     * @return ReplaceTextStats Diagnostic surface (all 12 keys
     *         present on every return; counters init to 0, arrays
     *         init to []):
     *         - `streams_scanned` (int): total `/FlateDecode` streams visited
     *           (regardless of BT/ET).
     *         - `content_streams_scanned` (int): subset of streams_scanned that
     *           contained a `BT` token.
     *         - `streams_modified` (int): how many streams had at least one match.
     *         - `replacements_per_needle` (array<string, int>): per-key counts.
     *         - `unmatched_needles` (string[]): keys with zero matches across
     *           the document.
     *         - `font_encoding_misses` (array): per-(oid, font) reports of
     *           placeholder chars the active font cannot encode.
     *         - `cid_split_mismatch` (array): per-(oid, font) reports of needles
     *           whose CID split mismatched the needle's UTF-8 layout.
     *         - `encoding_dict_unhandled` (array): per-(oid, font) reports of
     *           fonts whose /Encoding is an inline dict with /Differences —
     *           best-effort /BaseEncoding fallback applied but glyph overrides
     *           are NOT honoured in this version.
     *         - `contents_array_pages` (array<int, true>): set of content-stream
     *           OIDs that are part of a /Contents array (Tf state may not
     *           carry across array entries — PDF 1.7 §7.8.2).
     *         - `tj_arrays_modified` (int): how many TJ operators had at least
     *           one match-driven splice.
     *         - `subset_font_fallbacks_used` (int): how many times the Helvetica
     *           fallback fired because the active subset font could not encode
     *           the placeholder.
     *         - `rejected_substitutions` (array<string, string>): substitutions
     *           that failed input validation (empty needle, control chars in
     *           placeholder, or `()` / `\` in placeholder) with a reason string.
     */
    public function replace_text_in_document(array $substitutions): array {
        // Public-API parameter validation. Reject entries whose needle
        // is empty (would match everywhere) or whose placeholder
        // contains bytes we can't safely emit in a PDF string literal
        // without escape-handling we don't support yet.
        $rejectedSubstitutions = [];
        $cleanSubs = [];
        foreach ($substitutions as $needle => $placeholder) {
            if ($needle === '') {
                $rejectedSubstitutions[''] = 'needle is empty (would match everywhere)';
                continue;
            }
            if (preg_match('/[\\x00-\\x1F]/', $placeholder) === 1) {
                $rejectedSubstitutions[$needle] = 'placeholder contains control characters (0x00-0x1F)';
                continue;
            }
            if (preg_match('/[()\\\\]/', $placeholder) === 1) {
                $rejectedSubstitutions[$needle] = 'placeholder contains PDF-string-escape-significant chars (()\\); not supported in this version';
                continue;
            }
            $cleanSubs[$needle] = $placeholder;
        }

        $stats = [
            'streams_scanned'            => 0,
            'content_streams_scanned'    => 0,
            'streams_modified'           => 0,
            'replacements_per_needle'    => array_fill_keys(array_keys($cleanSubs), 0),
            'unmatched_needles'          => [],
            'font_encoding_misses'       => [],
            'cid_split_mismatch'         => [],
            'encoding_dict_unhandled'    => [],
            'contents_array_pages'       => [],
            'tj_arrays_modified'         => 0,
            'subset_font_fallbacks_used' => 0,
            'cross_operator_matches'     => 0,
            'cross_block_matches'        => 0,
            'logical_lines_built'        => 0,
            'rejected_substitutions'     => $rejectedSubstitutions,
        ];

        if (count($cleanSubs) === 0) {
            return $stats;
        }

        $fontContext = $this->buildFontContext();

        // Wrap the iterator loop in push_state/pop_state so a mid-loop
        // failure (gzcompress edge cases, OOM, CMap parse errors)
        // leaves _pdf_objects in a consistent state instead of
        // partially mutated.
        $this->push_state();
        try {
            foreach ($this->get_object_iterator() as $oid => $obj) {
                $value = $obj->get_value();
                if (!isset($value['Filter'])) {
                    continue;
                }

                // Accept a single `/FlateDecode` filter AND chained filter
                // arrays that include `/FlateDecode` (e.g.
                // `[/ASCII85Decode /FlateDecode]`, as emitted by ReportLab and
                // similar producers). `get_stream()` already decodes the full
                // chain via `apply_filter_chain_decode`, and
                // `PDFObject::set_stream($_, false)` re-encodes it via
                // `apply_filter_chain_encode` preserving the `/Filter` shape, so
                // the decode -> replace -> encode round-trip is symmetric for
                // the standard filters (ASCII85Decode / ASCIIHexDecode /
                // LZWDecode / RunLengthDecode). Streams whose chain SAPP cannot
                // decode return a non-string below and are skipped; chains it
                // cannot re-encode leave `set_stream` a no-op (fail-safe). The
                // `/Fl` abbreviation is still not handled.
                $filter = (string) $value['Filter'];
                if (strpos($filter, '/FlateDecode') === false) {
                    continue;
                }

                $decoded = $obj->get_stream(false);
                // Broader fail-soft guard: p_error returns false and some
                // sapp helpers historically returned null; treat any
                // non-string as an undecodeable stream and skip.
                if (!is_string($decoded)) {
                    continue;
                }

                // Total decoded streams that passed the filter check —
                // counted regardless of whether the BT heuristic accepts
                // them (so a caller can reason about the denominator).
                $stats['streams_scanned']++;

                // Skip non-content streams (font subsets, XObjects
                // without text, image-bearing streams). Cheap heuristic:
                // a content stream contains a `BT` token.
                if (strpos($decoded, 'BT') === false) {
                    continue;
                }
                $stats['content_streams_scanned']++;

                // Font set this stream MAY use. Built from any page whose
                // /Contents references this object. Falls back to ALL fonts
                // in the document if no page reference is found (common
                // for synthesised fixtures).
                $pageFonts = $fontContext['streamToFonts'][$oid] ?? $fontContext['allFonts'];

                // PDF 1.7 §7.8.2: when /Contents is an ARRAY, Tf state
                // carries across array entries. Each stream is processed
                // independently here (no concatenation), so the trailing
                // Tf state from a prior array entry is lost. Record once
                // per stream so callers can detect the limitation.
                if (isset($fontContext['contentsArrayOids'][$oid])) {
                    $stats['contents_array_pages'][$oid] = true;
                }

                $pageOid = $fontContext['streamToPage'][$oid] ?? null;

                $modified = $this->replaceInContentStream(
                    $decoded, $cleanSubs, $pageFonts, $pageOid, $oid, $stats
                );

                if ($modified !== $decoded) {
                    // `set_stream($_, false)` re-compresses via the filter
                    // chain configured on the object (FlateDecode here)
                    // and updates `Length`.
                    $obj->set_stream($modified, false);

                    // `get_object_iterator()` reads each object fresh from
                    // `$this->_buffer` via `PDFUtilFnc::find_object` —
                    // the mutation above lives only on the iterator's
                    // throwaway PDFObject reference. Register the
                    // modified object via `add_object` so subsequent
                    // `get_object()` calls (during `to_pdf_file_b`'s
                    // rebuild loop) return our copy. `add_object`
                    // preserves the generation-precedence guard and
                    // `_max_oid` bookkeeping that a direct
                    // `_pdf_objects[$oid] = $obj` write would skip.
                    if (isset($this->_pdf_objects[$oid])) {
                        $existingGen = $this->_pdf_objects[$oid]->get_generation();
                        if ($existingGen > $obj->get_generation()) {
                            // Re-construct with the higher generation
                            // and copy the mutated stream + value across.
                            $clone = new PDFObject($oid, $obj->get_value(), $existingGen);
                            $clone->set_stream($obj->get_stream(true), true);
                            $obj = $clone;
                        }
                    }
                    $this->add_object($obj);

                    $stats['streams_modified']++;
                }
            }
        } catch (\Throwable $e) {
            $this->pop_state();
            throw $e;
        }
        // Discard the snapshot — mutations are accepted into the
        // working set. (push_state + no pop_state on success path
        // leaves the snapshot on the backup stack; that's fine — it's
        // available for an explicit caller-side rollback.)

        foreach ($stats['replacements_per_needle'] as $needle => $count) {
            if ($count === 0) {
                $stats['unmatched_needles'][] = $needle;
            }
        }

        return $stats;
    }

    /**
     * Lazily create the standard `/Helvetica` Type1 font object and
     * register it on the named page's /Resources/Font as a fallback.
     *
     * Idempotent per page: subsequent calls for the same page return
     * the previously chosen resource name without re-injecting.
     * If the page has no own /Resources, the inherited resources are
     * promoted to page-level first (D3).
     *
     * @param int $pageOid The page object's OID.
     *
     * @return string|null The resource name (with leading slash) suitable
     *                     for `Tf` operators; null when injection fails
     *                     (e.g. page not found).
     */
    private function injectFallbackFontResource($pageOid) {
        if (isset($this->_fallback_font_injected[$pageOid])) {
            return $this->_fallback_font_injected[$pageOid];
        }

        $pageObj = $this->get_object($pageOid);
        if ($pageObj === false) return null;
        $pageValue = $pageObj->get_value();

        // Create the Helvetica font object once per document.
        if ($this->_fallback_font_oid === null) {
            $fontObj = $this->create_object([
                'Type'     => '/Font',
                'Subtype'  => '/Type1',
                'BaseFont' => '/Helvetica',
                'Encoding' => '/WinAnsiEncoding',
            ]);
            $this->_fallback_font_oid = $fontObj->get_oid();
        }

        // Resolve the page's Resources dict. Promote inherited resources
        // to page level if missing (D3).
        $resources = isset($pageValue['Resources']) ? $pageValue['Resources'] : null;
        if ($resources === null) {
            $pageValue['Resources'] = new \ddn\sapp\pdfvalue\PDFValueObject();
            $resources = $pageValue['Resources'];
        } elseif (is_a($resources, 'ddn\\sapp\\pdfvalue\\PDFValueReference')) {
            // Resources is a reference — resolve, deep-copy, attach to page.
            // If the referenced object can't be resolved, bail rather
            // than emit a stream that references an injected resource
            // that was never actually added (silent broken PDF). Same
            // for the Font sub-dict resolution below.
            $resObj = $this->get_indirect_object($resources);
            if (!is_object($resObj) || !method_exists($resObj, 'get_value')) {
                return null;
            }
            $pageValue['Resources'] = clone $resObj->get_value();
            $resources = $pageValue['Resources'];
        }

        // Resolve the Font sub-dict.
        $fontDict = isset($resources['Font']) ? $resources['Font'] : null;
        if ($fontDict === null) {
            $resources['Font'] = new \ddn\sapp\pdfvalue\PDFValueObject();
            $fontDict = $resources['Font'];
        } elseif (is_a($fontDict, 'ddn\\sapp\\pdfvalue\\PDFValueReference')) {
            $fontDictObj = $this->get_indirect_object($fontDict);
            if (!is_object($fontDictObj) || !method_exists($fontDictObj, 'get_value')) {
                return null;
            }
            $resources['Font'] = clone $fontDictObj->get_value();
            $fontDict = $resources['Font'];
        }

        // Pick a non-colliding resource name. Default: F-fb-anonym.
        // PDFValue base-class `get_keys()` can return `false` (not an
        // array) for some sub-classes; normalize to [] so in_array
        // doesn't TypeError on PHP 8.x. Keys may be stored with OR
        // without a leading slash depending on parser state — strip
        // any leading `/` so the strict-equality collision check sees
        // them in canonical form.
        $base = 'F-fb-anonym';
        $name = $base;
        $i = 2;
        $rawKeys = method_exists($fontDict, 'get_keys') ? $fontDict->get_keys() : [];
        if (!is_array($rawKeys)) {
            $rawKeys = [];
        }
        $existingKeys = [];
        foreach ($rawKeys as $k) {
            $k = (string) $k;
            $existingKeys[] = ($k !== '' && $k[0] === '/') ? substr($k, 1) : $k;
        }
        while (in_array($name, $existingKeys, true)) {
            $name = $base . '-' . ($i++);
        }

        $fontDict[$name] = new \ddn\sapp\pdfvalue\PDFValueReference($this->_fallback_font_oid);

        // Re-register the page object via add_object so the modified
        // Resources land in the rebuild path (same generation-
        // precedence + _max_oid bookkeeping as replace_text_in_document
        // uses for content-stream writes).
        $this->add_object($pageObj);

        $this->_fallback_font_injected[$pageOid] = '/' . $name;
        return '/' . $name;
    }

    /**
     * Try to encode a placeholder through the Helvetica fallback font.
     * Returns null when even WinAnsi can't encode (e.g. non-Latin chars).
     *
     * @param string $unicode UTF-8 placeholder.
     *
     * @return string|null Encoded bytes in /WinAnsiEncoding; null on miss.
     */
    private function encodeViaFallback($unicode) {
        $winAnsi = \ddn\sapp\FontEncoding::forName('/WinAnsiEncoding');
        $out = '';
        $chars = preg_split('//u', $unicode, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) return null;
        foreach ($chars as $ch) {
            $byte = $winAnsi->unicodeToByte($ch);
            if ($byte === null) return null;
            $out .= chr($byte);
        }
        return $out;
    }

    /**
     * Walk a single content stream's operators, resolve Tj operands
     * through the active font, match needles in text space, and
     * splice placeholders back into the stream.
     *
     * @param string                                                $stream        Decoded content stream bytes.
     * @param array<string, string>                                 $substitutions Needle → placeholder.
     * @param array<string, array{encoding: \ddn\sapp\FontEncoding, cmap: ?\ddn\sapp\CMap, baseFont: string}> $pageFonts Font-resource name → encoding/cmap.
     * @param int|null                                              $pageOid       The page this content stream belongs to (for fallback injection).
     * @param int                                                   $oid           Object id for diagnostic reporting.
     * @param array                                                 $stats         Diagnostic accumulator (modified by reference).
     *
     * @return string Possibly-modified stream bytes.
     */
    private function replaceInContentStream($stream, array $substitutions, array $pageFonts, $pageOid, $oid, array &$stats) {
        // Find every Tj operator and the Tf that's active at it.
        // The Tj operand is either `(literal)` or `<hex>`.
        // Pattern captures the operand region for surgical replacement.
        $activeFont = null;
        // Active font SIZE captured alongside the name so the fallback
        // emission can restore both with a `/Name <size> Tf` instead of
        // relying on q/Q (which PDF 1.7 §8.4.4 forbids inside a text
        // object and which non-conformant renderers handle inconsistently,
        // sometimes shifting the placeholder's rendered position).
        $activeFontSize = null;
        $output = '';
        $pos = 0;
        $len = strlen($stream);

        // Strategy: scan linearly, copying source bytes to $output, but
        // intercepting Tj / Tf operators. For each Tj operand, resolve
        // through the active font and run substitutions in text space.
        while ($pos < $len) {
            // Look ahead for the next operator of interest. We scan for
            // Tj / Tf / TJ, finding whichever comes first.
            $tjPos = $this->findNextOperator($stream, $pos, 'Tj');
            $tfPos = $this->findNextOperator($stream, $pos, 'Tf');
            $bigTjPos = $this->findNextOperator($stream, $pos, 'TJ');

            // Pick the earliest match (if any).
            $candidates = [];
            if ($tjPos >= 0)     $candidates[] = [$tjPos, 'Tj'];
            if ($tfPos >= 0)     $candidates[] = [$tfPos, 'Tf'];
            if ($bigTjPos >= 0)  $candidates[] = [$bigTjPos, 'TJ'];

            if (count($candidates) === 0) {
                $output .= substr($stream, $pos);
                break;
            }
            usort($candidates, function ($a, $b) { return $a[0] - $b[0]; });
            [$next, $nextOp] = $candidates[0];

            if ($nextOp === 'TJ') {
                $fontInfo = ($activeFont !== null) ? ($pageFonts[$activeFont] ?? null) : null;
                $tjResult = $this->processTjArray($stream, $next, $pos, $substitutions, $fontInfo, $pageOid, $oid, $stats, $activeFont, $activeFontSize);
                if ($tjResult !== null) {
                    [$preChunk, $replacement, $opEnd] = $tjResult;
                    $output .= $preChunk . $replacement;
                    $pos = $opEnd;
                    continue;
                }
                // Fall-through: couldn't parse; copy through.
                $opEnd = $next + 2;
                $output .= substr($stream, $pos, $opEnd - $pos);
                $pos = $opEnd;
                continue;
            }

            if ($nextOp === 'Tf') {
                // Find the font name preceding the Tf operator. Pattern:
                // `/Name <size> Tf` — anchored to END with `\z`, scanned
                // only across the 64-byte window leading up to `Tf`. Using
                // `preg_match` with an offset alone is unsafe because it
                // searches forward from the offset; we need to bound the
                // search to a *window* ending at $next + 2 so a missing
                // Tf in the window doesn't accidentally match a Tf far
                // later in the stream.
                $tfEnd = $next + 2;
                $windowStart = max(0, $next - 64);
                $window = substr($stream, $windowStart, $tfEnd - $windowStart);
                if (preg_match('#/([A-Za-z0-9_+\-]+)\s+([-+\d.]+)\s+Tf\z#', $window, $m)) {
                    $activeFont = $m[1];
                    $activeFontSize = $m[2];
                }
                // Copy through the Tf operator unchanged.
                $output .= substr($stream, $pos, $tfEnd - $pos);
                $pos = $tfEnd;
                continue;
            }

            // Tj: find the operand region just before this position.
            $operandInfo = $this->findTjOperand($stream, $next);
            if ($operandInfo === null) {
                // Couldn't parse — copy through.
                $opEnd = $next + 2;
                $output .= substr($stream, $pos, $opEnd - $pos);
                $pos = $opEnd;
                continue;
            }
            [$operandStart, $operandEnd, $operandBytes, $operandShape] = $operandInfo;

            // Copy from $pos up to operand start.
            $output .= substr($stream, $pos, $operandStart - $pos);

            // Resolve operand to Unicode via active font.
            $fontInfo = ($activeFont !== null) ? ($pageFonts[$activeFont] ?? null) : null;
            if ($fontInfo !== null
                && isset($fontInfo['encoding_dict_unhandled'])
                && $fontInfo['encoding_dict_unhandled'] !== null
                && !isset($stats['encoding_dict_unhandled'][$oid][$activeFont])) {
                // Record the dict-shape silent-degradation once per
                // (oid, font-resource-name). Resolution still happens
                // via the best-effort /BaseEncoding fallback, but
                // /Differences glyph overrides are NOT applied.
                $stats['encoding_dict_unhandled'][$oid][$activeFont] = $fontInfo['encoding_dict_unhandled'];
            }
            $resolvedText = $this->resolveOperandToUnicode($operandBytes, $operandShape, $fontInfo);

            // Try each substitution.
            $newOperandBytes = $operandBytes;
            $newOperandShape = $operandShape;
            $appliedAny = false;
            foreach ($substitutions as $needle => $placeholder) {
                if ($resolvedText === '' || strpos($resolvedText, $needle) === false) {
                    continue;
                }

                // Prefer the Helvetica fallback (q/Q-wrapped, PR #08) over
                // the active-font encoding. The active font is almost
                // always a subset font that only contains glyphs for
                // characters used in the source document — placeholders
                // contain characters (e.g. `[`, `]`) that the source
                // typically doesn't use, so `encodeUnicodeViaFont` would
                // emit bytes whose glyphs the font then can't render
                // (.notdef shows up as a blank or square). Fallback
                // guarantees a base font with the full WinAnsi glyph
                // set, makes the placeholder visually distinct, and
                // lets surrounding text flow past as overflow (q/Q
                // doesn't restore the text matrix, so the advance
                // naturally pushes following text right).
                $encodedPlaceholder = null;
                $useFallback = false;
                $fallbackResourceName = null;
                if ($pageOid !== null) {
                    $fallbackBytes = $this->encodeViaFallback($placeholder);
                    if ($fallbackBytes !== null) {
                        $fallbackResourceName = $this->injectFallbackFontResource($pageOid);
                        if ($fallbackResourceName !== null) {
                            $encodedPlaceholder = $fallbackBytes;
                            $useFallback = true;
                        }
                    }
                }
                if ($encodedPlaceholder === null) {
                    // No fallback available (no pageOid, or WinAnsi
                    // can't encode the placeholder, or fallback resource
                    // injection failed) — fall back to the active font.
                    $encodedPlaceholder = $this->encodeUnicodeViaFont($placeholder, $fontInfo);
                    if ($encodedPlaceholder === null) {
                        $baseFont = $fontInfo['baseFont'] ?? '(unknown)';
                        $stats['font_encoding_misses'][$oid][$needle] = $baseFont;
                        continue;
                    }
                }

                // Multi-occurrence-safe splice loop: keep splicing until
                // the resolved text no longer contains the needle. Each
                // splice that actually mutates the operand (return value
                // !== input) counts as one replacement. cid_split-mismatch
                // skips (where spliceOperand returns the unchanged input)
                // do NOT bump replacements_per_needle — they're already
                // recorded under cid_split_mismatch.
                //
                // Subset-font fallback path: when the active font can't
                // encode the placeholder, we splice an empty marker in
                // the existing operand (removing the needle bytes) and
                // queue a q/Q-wrapped Helvetica `(placeholder) Tj` to be
                // appended after the surrounding Tj operator.
                $safetyCap = 1024;
                while ($safetyCap-- > 0 && $resolvedText !== '' && strpos($resolvedText, $needle) !== false) {
                    if ($useFallback === true) {
                        $spliced = $this->spliceOperand(
                            $newOperandBytes, $newOperandShape, $needle, '', $fontInfo, $stats, $oid
                        );
                        if ($spliced === $newOperandBytes) {
                            break;
                        }
                        $newOperandBytes = $spliced;
                        $stats['subset_font_fallbacks_used']++;
                        if (!isset($pendingFallbackAppend)) {
                            $pendingFallbackAppend = '';
                        }
                        // Prefer the inline Tf-restore shape (no q/Q
                        // inside the text object) when the active font
                        // + size are known. Same rationale as the
                        // TJ-array renderer above.
                        if ($activeFont !== null && $activeFontSize !== null) {
                            $pendingFallbackAppend .= ' ' . $fallbackResourceName . ' 10 Tf ('
                                . $this->escapePdfLiteral($encodedPlaceholder) . ') Tj /'
                                . $activeFont . ' ' . $activeFontSize . ' Tf';
                        } else {
                            $pendingFallbackAppend .= ' q ' . $fallbackResourceName . ' 10 Tf ('
                                . $this->escapePdfLiteral($encodedPlaceholder) . ') Tj Q';
                        }
                    } else {
                        $spliced = $this->spliceOperand(
                            $newOperandBytes, $newOperandShape, $needle, $encodedPlaceholder, $fontInfo, $stats, $oid
                        );
                        if ($spliced === $newOperandBytes) {
                            break;
                        }
                        $newOperandBytes = $spliced;
                    }
                    $stats['replacements_per_needle'][$needle]++;
                    $appliedAny = true;
                    $resolvedText = $this->resolveOperandToUnicode($newOperandBytes, $newOperandShape, $fontInfo);
                }
            }

            // Emit the (possibly modified) operand back into the output,
            // preserving the original shape (`(...)` vs `<...>`).
            if ($newOperandShape === 'hex') {
                $output .= '<' . strtoupper(bin2hex($newOperandBytes)) . '>';
            } else {
                $output .= '(' . $this->escapePdfLiteral($newOperandBytes) . ')';
            }

            // Copy from operand-end through the Tj operator.
            $opEnd = $next + 2;
            $output .= substr($stream, $operandEnd, $opEnd - $operandEnd);
            // If a fallback splice was queued (PR #08), append the
            // q/Q-wrapped placeholder Tj after the original operator.
            if (isset($pendingFallbackAppend) && $pendingFallbackAppend !== '') {
                $output .= $pendingFallbackAppend;
                unset($pendingFallbackAppend);
            }
            $pos = $opEnd;
        }

        // Phase 2 (cross-bt-et-matching): run the cross-operator post-pass
        // on the linear matcher's output. Catches needles whose text spans
        // multiple Tj/TJ operators within a single BT/ET — the linear
        // matcher above sees one operator at a time and so cannot find
        // these. The post-pass is no-op when no multi-operator block has a
        // cross-op needle match.
        $output = $this->applyCrossOperatorReplacements(
            $output, $substitutions, $pageFonts, $pageOid, $oid, $stats
        );

        // Phase 3 (cross-bt-et-matching): cross-BT/ET pass — finds
        // needles whose text spans separate text objects within the
        // same logical line (same Y, same font, monotonic X). Catches
        // tagged-PDF /Span splits (e.g. "via d.smits" in one BT/ET +
        // "@amsterdam.nl" in the next) and Word table-cell
        // character-per-BT/ET layouts.
        $output = $this->applyCrossBlockReplacements(
            $output, $substitutions, $pageFonts, $pageOid, $oid, $stats
        );

        // Phase 4 (cross-line-matching): cross-LINE pass — finds needles
        // whose text wraps across a visual line break (e.g. "14 mei" at
        // the end of one line + "2026" at the start of the next). Pairs
        // vertically adjacent same-font blocks and matches across the
        // boundary, treating the wrap as the whitespace it represents.
        $output = $this->applyCrossLineReplacements(
            $output, $substitutions, $pageFonts, $pageOid, $oid, $stats
        );

        return $output;
    }

    /**
     * Process a TJ array operator: parse the `[...]` operand, match
     * needles against the concatenated text-space view, and splice
     * placeholders preserving outside-the-match kerning.
     *
     * Returns null when the array can't be parsed; otherwise a tuple
     * `[preChunk, replacement, opEndOffset]` where:
     *   - preChunk: bytes from `$copyFrom` up to the `[` of the TJ operand
     *   - replacement: the new operator(s) to emit in place of the
     *                  original `[...] TJ` (could be a single `(p) Tj`,
     *                  a pair of `[...] TJ ... Tj`, or three operators
     *                  per D2's middle-match case)
     *   - opEndOffset: byte offset just after the original `TJ` operator
     *
     * Per PR #07 design D2, four splice shapes per match position:
     *   - Full match → `(placeholder) Tj`
     *   - Prefix match → `(placeholder) Tj [<remaining>] TJ`
     *   - Suffix match → `[<leading>] TJ (placeholder) Tj`
     *   - Middle match → `[<leading>] TJ (placeholder) Tj [<trailing>] TJ`
     *
     * Matches whose start or end does NOT align with a TJ fragment
     * boundary are skipped + recorded as `cid_split_mismatch` (the
     * same diagnostic key as the CID-interior-split case in PR #06).
     *
     * @param string                $stream        Decoded content stream.
     * @param int                   $tjPos         Byte offset of the `TJ` operator.
     * @param int                   $copyFrom      Byte offset from which to copy unchanged into preChunk.
     * @param array<string, string> $substitutions Needle → placeholder.
     * @param array|null            $fontInfo      Active font info.
     * @param int|null              $pageOid       Page owning this content stream (for fallback injection).
     * @param int                   $oid           Object id (diagnostic).
     * @param array                 $stats         Diagnostic accumulator (by-ref).
     * @param string|null           $activeFontResource Resource name of the active Tf font (e.g. "F3"). Used to
     *                                              restore the font with an explicit `/Name size Tf` after a
     *                                              fallback-emitted placeholder, avoiding q/Q inside the text object.
     * @param string|null           $activeFontSize Size string captured from the active Tf operator (e.g. "11.04").
     *                                              Paired with $activeFontResource for the restoration Tf.
     *
     * @return array{0:string,1:string,2:int}|null
     */
    private function processTjArray($stream, $tjPos, $copyFrom, array $substitutions, $fontInfo, $pageOid, $oid, array &$stats, $activeFontResource = null, $activeFontSize = null) {
        // Find the `]` immediately preceding the TJ operator (skip whitespace).
        $closeBracket = -1;
        for ($i = $tjPos - 1; $i >= 0; $i--) {
            $c = $stream[$i];
            if (preg_match('/\s/', $c)) continue;
            if ($c === ']') { $closeBracket = $i; break; }
            return null;  // unexpected byte; bail to fall-through
        }
        if ($closeBracket < 0) return null;

        // Find the matching `[` (counting depth-1 only — TJ arrays
        // don't contain nested arrays per PDF 1.7 §9.4.3).
        $openBracket = -1;
        for ($i = $closeBracket - 1; $i >= 0; $i--) {
            $c = $stream[$i];
            if ($c === '[') { $openBracket = $i; break; }
            if ($c === ']') return null;  // nested — out of spec, bail
        }
        if ($openBracket < 0) return null;

        $arrayContent = substr($stream, $openBracket + 1, $closeBracket - $openBracket - 1);
        $entries = $this->parseTjArrayContent($arrayContent);
        if ($entries === null) return null;

        // Iteratively splice each match. Outer loop continues until no
        // remaining substitution finds any match in the (possibly already
        // modified) concatenated text — this addresses the multi-match
        // regression: previously the function returned on the first hit,
        // missing further occurrences (multiple needles OR the same
        // needle twice across kerning-split fragments inside one TJ).
        $totalMatchedInThisTj = 0;
        $safetyCap = 1024;
        while ($safetyCap-- > 0) {
            // Re-resolve fragment texts against current $entries each
            // iteration — splicing changes the layout.
            $fragmentTexts = [];
            $concatenated = '';
            $fragmentIndices = [];
            foreach ($entries as $k => $entry) {
                if ($entry['kind'] !== 'text') continue;
                $resolved = $this->resolveOperandToUnicode($entry['bytes'], $entry['shape'], $fontInfo);
                $fragmentTexts[$k] = $resolved;
                $fragmentIndices[] = $k;
                $concatenated .= $resolved;
            }

            $foundMatch = false;
            foreach ($substitutions as $needle => $placeholder) {
                if ($concatenated === '' || strpos($concatenated, $needle) === false) {
                    continue;
                }

                // Locate match start/end at fragment-boundary granularity.
                $matchPos = strpos($concatenated, $needle);
                $matchEnd = $matchPos + strlen($needle);

                $startFragIdx = -1;
                $endFragIdx = -1;
                // When a needle's start or end lands in the interior of a
                // single literal fragment (Word's PDF output packs each
                // kerning group as a multi-char literal like `(Aa)(n)(we)
                // (z)(ig)` — a needle ending mid-fragment used to bail
                // here as `cid_split_mismatch`), we split the fragment.
                // The matched portion stays in the splice; the unmatched
                // head/tail is preserved as a new sibling text entry.
                // Hex/CID fragments still bail — splitting them safely
                // requires CMap-aware width arithmetic that the simple
                // byte slice below doesn't cover.
                $startHeadBytes = '';
                $endTailBytes = '';
                $accum = 0;
                $splitMismatch = false;
                foreach ($fragmentIndices as $fragIdx) {
                    $fragStart = $accum;
                    $fragText = $fragmentTexts[$fragIdx];
                    $fragEnd = $accum + strlen($fragText);
                    $entry = $entries[$fragIdx];
                    if ($fragStart === $matchPos) {
                        $startFragIdx = $fragIdx;
                    } elseif ($fragStart < $matchPos && $fragEnd > $matchPos) {
                        // Match start interior to this fragment.
                        if ($entry['shape'] !== 'literal'
                            || strlen($entry['bytes']) !== strlen($fragText)) {
                            // Hex shape, or simple-font encoding where
                            // raw bytes don't map 1:1 to resolved bytes
                            // (multi-byte UTF-8 codepoints, ligature
                            // CMap entries). Conservative bail.
                            $stats['cid_split_mismatch'][$oid][$needle] = $fragStart;
                            $splitMismatch = true;
                            break;
                        }
                        $headLen = $matchPos - $fragStart;
                        $startHeadBytes = substr($entry['bytes'], 0, $headLen);
                        // Truncate the start fragment in-place to the
                        // matched suffix; the head bytes will be re-
                        // inserted as a new entry during the splice.
                        $entries[$fragIdx]['bytes'] = substr($entry['bytes'], $headLen);
                        $startFragIdx = $fragIdx;
                    }
                    if ($fragEnd === $matchEnd) {
                        $endFragIdx = $fragIdx;
                    } elseif ($fragStart < $matchEnd && $fragEnd > $matchEnd) {
                        // Match end interior to this fragment.
                        $entryNow = $entries[$fragIdx];
                        // The shape/length check uses $entry from BEFORE
                        // the start-split mutation above; for the rare
                        // start==end-fragment case the start-split
                        // already shortened entry['bytes'], so check the
                        // remaining-suffix length against the remaining-
                        // suffix resolved-text length.
                        $effectiveBytes = $entryNow['bytes'];
                        $effectiveFragText = $startFragIdx === $fragIdx
                            ? substr($fragText, $matchPos - $fragStart)
                            : $fragText;
                        if ($entryNow['shape'] !== 'literal'
                            || strlen($effectiveBytes) !== strlen($effectiveFragText)) {
                            $stats['cid_split_mismatch'][$oid][$needle] = $fragEnd;
                            $splitMismatch = true;
                            break;
                        }
                        $matchedTailLen = $matchEnd - max($fragStart, $matchPos);
                        $endTailBytes = substr($effectiveBytes, $matchedTailLen);
                        $entries[$fragIdx]['bytes'] = substr($effectiveBytes, 0, $matchedTailLen);
                        $endFragIdx = $fragIdx;
                        break;
                    }
                    $accum = $fragEnd;
                }
                if ($splitMismatch) continue;
                if ($startFragIdx < 0 || $endFragIdx < 0) continue;

                // Prefer the Helvetica fallback (q/Q-wrapped) over the
                // active-font encoding — same reasoning as the Tj-direct
                // path above. Subset fonts almost never carry the
                // bracket/punctuation glyphs the placeholder needs, and
                // rendering via the fallback also lets surrounding text
                // overflow naturally past the placeholder rather than
                // visually compressing into the source word's advance.
                $tjUseFallback = false;
                $tjFallbackResourceName = null;
                $encodedPlaceholder = null;
                if ($pageOid !== null) {
                    $fallbackBytes = $this->encodeViaFallback($placeholder);
                    if ($fallbackBytes !== null) {
                        $tjFallbackResourceName = $this->injectFallbackFontResource($pageOid);
                        if ($tjFallbackResourceName !== null) {
                            $encodedPlaceholder = $fallbackBytes;
                            $tjUseFallback = true;
                        }
                    }
                }
                if ($encodedPlaceholder === null) {
                    $encodedPlaceholder = $this->encodeUnicodeViaFont($placeholder, $fontInfo);
                    if ($encodedPlaceholder === null) {
                        $baseFont = $fontInfo['baseFont'] ?? '(unknown)';
                        $stats['font_encoding_misses'][$oid][$needle] = $baseFont;
                        continue;
                    }
                }

                // Splice: replace entries [startFragIdx..endFragIdx]
                // (and any kerning entries between them) with a single
                // marker entry tagged 'placeholder'. At render time
                // each 'placeholder' entry becomes its own `(...) Tj`
                // (or a `q ResourceName 12 Tf (...) Tj Q` wrapper for
                // the subset-font-fallback path); consecutive non-
                // placeholder entries are grouped into `[...] TJ`
                // arrays — preserving the D2 split shape while
                // supporting multi-match.
                //
                // Placeholder always emitted as literal — the
                // documented D2 contract; hex-shape inheritance from
                // the first matched fragment would emit hex bytes
                // from a freshly-encoded placeholder that the
                // original fragment's font may not even accept.
                $placeholderEntry = [
                    'kind'  => 'placeholder',
                    'bytes' => $encodedPlaceholder,
                    'shape' => 'literal',
                ];
                if ($tjUseFallback === true) {
                    $placeholderEntry['fallback_resource'] = $tjFallbackResourceName;
                    // Attach the active font + size so the renderer can
                    // restore them with an inline `/Name size Tf` after
                    // the placeholder, avoiding q/Q inside the text
                    // object (PDF 1.7 §8.4.4) — non-conformant
                    // renderers reset the text matrix on Q, which
                    // shifted placeholders away from the source word's
                    // position. Empty when the caller couldn't capture
                    // the active Tf (rare); renderer then falls back to
                    // the legacy q/Q shape.
                    if ($activeFontResource !== null && $activeFontSize !== null) {
                        $placeholderEntry['restore_font_resource'] = $activeFontResource;
                        $placeholderEntry['restore_font_size']     = $activeFontSize;
                    }
                    $stats['subset_font_fallbacks_used']++;
                }
                $newEntries = [];
                foreach ($entries as $k => $entry) {
                    if ($k < $startFragIdx) {
                        $newEntries[] = $entry;
                    } elseif ($k === $startFragIdx) {
                        // Re-emit the pre-match head of the start fragment
                        // (set by the literal-split branch above). Empty
                        // when the match started cleanly at a fragment
                        // boundary, in which case nothing is prepended.
                        if ($startHeadBytes !== '') {
                            $newEntries[] = [
                                'kind'  => 'text',
                                'bytes' => $startHeadBytes,
                                'shape' => 'literal',
                            ];
                        }
                        $newEntries[] = $placeholderEntry;
                        // Re-emit the post-match tail of the end fragment.
                        // For the start==end fragment case both head and
                        // tail come from the same original entry.
                        if ($endTailBytes !== '') {
                            $newEntries[] = [
                                'kind'  => 'text',
                                'bytes' => $endTailBytes,
                                'shape' => 'literal',
                            ];
                        }
                    } elseif ($k > $endFragIdx) {
                        $newEntries[] = $entry;
                    }
                    // Entries inside (startFragIdx, endFragIdx] are dropped.
                }
                $entries = $newEntries;

                // Lazy-init the per-needle counter — earlier code
                // `$stats['replacements_per_needle'][$needle]++` could
                // emit a PHP 7.4 "Undefined index" notice if the first
                // ever match for $needle landed inside a TJ array
                // (the outer replace_text_in_document only pre-keys
                // the array for substitutions supplied at call time,
                // which it does — but the lazy init keeps the
                // contract explicit and resilient to future changes
                // in the entry point).
                if (!isset($stats['replacements_per_needle'][$needle])) {
                    $stats['replacements_per_needle'][$needle] = 0;
                }
                $stats['replacements_per_needle'][$needle]++;
                $totalMatchedInThisTj++;
                $foundMatch = true;
                break;  // restart outer while-loop with re-resolved $entries
            }

            if (!$foundMatch) break;
        }

        if ($totalMatchedInThisTj === 0) {
            return null;
        }

        // Re-render with the D2 split shape: each `placeholder` entry
        // becomes its own `(placeholder) Tj`; consecutive non-
        // placeholder entries are bundled into a `[...] TJ` array.
        // Kerning at the boundary of a placeholder is trimmed (a TJ
        // array shouldn't end OR start with a dangling kerning value).
        $stats['tj_arrays_modified']++;

        $groups = [];          // ordered list of either ['tj' => [...]] or ['placeholder' => entry]
        $currentArr = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'placeholder') {
                if (!empty($currentArr)) {
                    // Trim trailing kerning before flushing.
                    $lastIdx = count($currentArr) - 1;
                    while ($lastIdx >= 0 && $currentArr[$lastIdx]['kind'] === 'kern') {
                        array_pop($currentArr);
                        $lastIdx--;
                    }
                    if (!empty($currentArr)) {
                        $groups[] = ['tj' => $currentArr];
                    }
                    $currentArr = [];
                }
                $groups[] = ['placeholder' => $entry];
            } else {
                // Suppress leading kerning right after a placeholder.
                if (empty($currentArr)
                    && $entry['kind'] === 'kern'
                    && !empty($groups)
                    && isset($groups[count($groups) - 1]['placeholder'])) {
                    continue;
                }
                $currentArr[] = $entry;
            }
        }
        if (!empty($currentArr)) {
            // Trim trailing kerning on the final group.
            $lastIdx = count($currentArr) - 1;
            while ($lastIdx >= 0 && $currentArr[$lastIdx]['kind'] === 'kern') {
                array_pop($currentArr);
                $lastIdx--;
            }
            if (!empty($currentArr)) {
                $groups[] = ['tj' => $currentArr];
            }
        }

        $parts = [];
        foreach ($groups as $group) {
            if (isset($group['placeholder'])) {
                $ph = $group['placeholder'];
                if (isset($ph['fallback_resource']) && $ph['fallback_resource'] !== null) {
                    // Spec-compliant inline font switch: change to
                    // Helvetica for the placeholder Tj, then restore
                    // the active font + size with another Tf. Avoids
                    // q/Q inside the text object (PDF 1.7 §8.4.4
                    // forbids those operators between BT/ET; some
                    // renderers honor the spec by resetting the text
                    // matrix on Q, which visually shifts the
                    // placeholder away from the source word).
                    if (isset($ph['restore_font_resource'], $ph['restore_font_size'])) {
                        $parts[] = $ph['fallback_resource'] . ' 10 Tf ('
                            . $this->escapePdfLiteral($ph['bytes']) . ') Tj /'
                            . $ph['restore_font_resource'] . ' '
                            . $ph['restore_font_size'] . ' Tf';
                    } else {
                        // Caller didn't supply the restore info — fall
                        // back to the legacy q/Q shape rather than
                        // leaving the document in Helvetica for all
                        // subsequent text in this BT/ET block.
                        $parts[] = 'q ' . $ph['fallback_resource'] . ' 10 Tf ('
                            . $this->escapePdfLiteral($ph['bytes']) . ') Tj Q';
                    }
                } else {
                    $parts[] = $this->renderTjFragment([
                        'kind'  => 'text',
                        'bytes' => $ph['bytes'],
                        'shape' => 'literal',
                    ]) . ' Tj';
                }
            } else {
                $parts[] = $this->renderTjArray($group['tj']) . ' TJ';
            }
        }
        $replacement = implode(' ', $parts);

        $preChunk = substr($stream, $copyFrom, $openBracket - $copyFrom);
        $opEnd = $tjPos + 2;
        return [$preChunk, $replacement, $opEnd];
    }

    /**
     * Parse a TJ array's inner content into a list of entries.
     *
     * Returns null on malformed input. Each entry is one of:
     *   - ['kind' => 'text', 'bytes' => string, 'shape' => 'literal'|'hex']
     *   - ['kind' => 'kern', 'value' => string (the original numeric token)]
     *
     * @param string $arrayContent Bytes between `[` and `]` of a TJ operator.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function parseTjArrayContent($arrayContent) {
        $entries = [];
        $i = 0;
        $len = strlen($arrayContent);
        // PDF whitespace per PDF 1.7 §7.2.3 Table 1: NUL, HT, LF, FF, CR, SP.
        // Byte-level strpos check is ~50× faster than `preg_match('/\s/')`
        // per byte on large TJ arrays.
        $wsChars = "\x00\x09\x0A\x0C\x0D\x20";
        while ($i < $len) {
            $c = $arrayContent[$i];
            if (strpos($wsChars, $c) !== false) { $i++; continue; }
            // PDF 1.7 §7.2.4: `%`-to-EOL comments are whitespace-equivalent.
            // Word doesn't usually emit them inside TJ arrays, but spec-
            // conformant readers must skip them.
            if ($c === '%') {
                while ($i < $len && $arrayContent[$i] !== "\n" && $arrayContent[$i] !== "\r") {
                    $i++;
                }
                continue;
            }
            if ($c === '(') {
                // Literal string: scan to matching `)` with escape handling.
                $depth = 1;
                $start = $i + 1;
                $j = $start;
                while ($j < $len && $depth > 0) {
                    $cj = $arrayContent[$j];
                    if ($cj === '\\' && $j + 1 < $len) { $j += 2; continue; }
                    if ($cj === '(') $depth++;
                    elseif ($cj === ')') $depth--;
                    if ($depth > 0) $j++;
                }
                if ($depth !== 0) return null;
                $literal = substr($arrayContent, $start, $j - $start);
                $entries[] = ['kind' => 'text', 'bytes' => $this->unescapePdfLiteral($literal), 'shape' => 'literal'];
                $i = $j + 1;
            } elseif ($c === '<') {
                $end = strpos($arrayContent, '>', $i);
                if ($end === false) return null;
                $hex = substr($arrayContent, $i + 1, $end - $i - 1);
                $hex = preg_replace('/\s+/', '', $hex);
                // PDF 1.7 §7.3.4.3: odd-length hex strings are implicitly
                // padded with a trailing `0`. Earlier code did
                // `@hex2bin($hex) ?: ''` — `hex2bin` returns false on odd
                // length (suppressed by `@`), the `?:` collapsed it to
                // empty string, silently dropping a character.
                if ((strlen($hex) & 1) === 1) {
                    $hex .= '0';
                }
                $bytes = hex2bin($hex);
                if ($bytes === false) {
                    return null;
                }
                $entries[] = ['kind' => 'text', 'bytes' => $bytes, 'shape' => 'hex'];
                $i = $end + 1;
            } elseif ($c === '-' || $c === '+' || $c === '.' || ($c >= '0' && $c <= '9')) {
                // Numeric kerning value. PDF 1.7 §7.3.3 explicitly
                // forbids exponent notation in Numeric Objects, so the
                // tokenizer rejects `e`/`E` to keep byte-offset
                // alignment with conformant parsers (a producer that
                // emits an `e` glyph token AFTER a CID `<65>` would
                // otherwise get its `e` swallowed as part of a number).
                $j = $i;
                while ($j < $len) {
                    $cj = $arrayContent[$j];
                    if ($cj === '-' || $cj === '+' || $cj === '.' || ($cj >= '0' && $cj <= '9')) {
                        $j++;
                    } else {
                        break;
                    }
                }
                $entries[] = ['kind' => 'kern', 'value' => substr($arrayContent, $i, $j - $i)];
                $i = $j;
            } else {
                // Unexpected character. Outer caller falls through and
                // copies bytes verbatim from $pos to $opEnd unchanged
                // (TJ is then emitted as-is in the output stream).
                return null;
            }
        }
        return $entries;
    }

    /**
     * Render a list of TJ entries back to the array form: `[...]`
     * content (no surrounding brackets).
     *
     * @param array<int, array<string, mixed>> $entries
     *
     * @return string
     */
    private function renderTjArray(array $entries) {
        $parts = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'text') {
                $parts[] = $this->renderTjFragment($entry);
            } else {
                $parts[] = $entry['value'];
            }
        }
        return '[' . implode(' ', $parts) . ']';
    }

    /**
     * Render a single text fragment in its original shape.
     *
     * @param array<string, mixed> $entry Entry with 'bytes' and 'shape'.
     *
     * @return string
     */
    private function renderTjFragment($entry) {
        if ($entry['shape'] === 'hex') {
            return '<' . strtoupper(bin2hex($entry['bytes'])) . '>';
        }
        return '(' . $this->escapePdfLiteral($entry['bytes']) . ')';
    }

    /**
     * Parse a decoded content stream into a structured model of BT/ET
     * text blocks — the foundation for cross-operator and cross-BT/ET
     * needle matching planned by `feat-cross-bt-et-matching`. The model
     * is READ-ONLY: this method does not mutate the stream and is NOT
     * yet wired into the matching path; it exists so the upcoming phases
     * have a stable shape to build on.
     *
     * Each returned block records:
     *   - bt_offset, et_offset: byte offsets of the literal `BT`/`ET`
     *     tokens (operator-position, not surrounding whitespace).
     *   - font_name, font_size: the active Tf at the start of the block.
     *     Font state carries across BT/ET (it's part of the graphics
     *     state per PDF 1.7 §8.4); the parser tracks Tf operators both
     *     inside and outside text objects and snapshots the values at
     *     each BT. Mid-block Tf changes update the snapshot in place
     *     so later operators inside the same block see the new font.
     *   - tm: the effective text matrix (a,b,c,d,e,f) after the most
     *     recent Tm operator inside the block. Td/T* are NOT honoured
     *     yet (D3 — Word and modern producers nearly always emit Tm
     *     directly). Defaults to identity (1,0,0,1,0,0) when no Tm
     *     is seen.
     *   - operators: ordered list of Tj/TJ operators with byte offsets,
     *     parsed entries (same shape as parseTjArrayContent for TJ; a
     *     single synthetic text-entry for Tj), and resolved UTF-8 text.
     *
     * Implementation: scans via findNextOperator (string-state-aware)
     * so phantom tokens inside string literals never trigger false
     * block boundaries. Operand parsing reuses parseTjArrayContent +
     * findTjOperand + resolveOperandToUnicode so resolved_text matches
     * what the existing matcher sees today.
     *
     * @param string $stream    Decoded content stream bytes.
     * @param array  $pageFonts Resource-name → font-info map for the
     *                          owning page (from buildFontContext()).
     *
     * @return array<int, array{bt_offset:int,et_offset:int,font_name:?string,font_size:?string,tm:array<string,float>,operators:array<int,array<string,mixed>>}>
     *
     * @spec openspec/changes/feat-cross-bt-et-matching/design.md (D1, D3-D5)
     */
    private function parseContentStreamModel($stream, array $pageFonts): array
    {
        $blocks = [];
        $activeFontName = null;
        $activeFontSize = null;
        $pos = 0;
        $len = strlen($stream);

        while ($pos < $len) {
            $btPos = $this->findNextOperator($stream, $pos, 'BT');
            if ($btPos < 0) {
                // No more text objects. Tf operators in [pos, len) only
                // affect a (non-existent) later block; ignore them.
                break;
            }
            // Tf operators in [pos, btPos] update the active font for
            // this and subsequent blocks (Tf is graphics state).
            $this->applyTfInRange($stream, $pos, $btPos, $activeFontName, $activeFontSize);

            $etPos = $this->findNextOperator($stream, $btPos + 2, 'ET');
            if ($etPos < 0) {
                // Unterminated text object. The existing linear walker
                // still emits these bytes verbatim; we just don't model
                // them.
                break;
            }

            $blockFontName = $activeFontName;
            $blockFontSize = $activeFontSize;
            $tm = ['a' => 1.0, 'b' => 0.0, 'c' => 0.0,
                   'd' => 1.0, 'e' => 0.0, 'f' => 0.0];
            $operators = [];

            $innerPos = $btPos + 2;
            while ($innerPos < $etPos) {
                // Single forward scan for the NEXT operator of any tracked
                // type. The previous approach called findNextOperator() once
                // per type (Tj/TJ/Tf/Tm) every step — and a type absent from
                // the rest of the block scanned to its next occurrence
                // anywhere ahead, making the parse O(n^2) per stream (the
                // dominant cost of replace_text_in_document on large PDFs).
                // findNextAnyOperator advances only to the next operator, so
                // the per-block scan is linear. Behaviour is identical: the
                // old code already picked the earliest-position candidate.
                [$opPos, $opName] = $this->findNextAnyOperator($stream, $innerPos, ['Tj', 'TJ', 'Tf', 'Tm']);
                if ($opPos < 0 || $opPos >= $etPos) break;

                if ($opName === 'Tf') {
                    $windowStart = max($innerPos, $opPos - 64);
                    $window = substr($stream, $windowStart, $opPos + 2 - $windowStart);
                    if (preg_match('#/([A-Za-z0-9_+\-]+)\s+([-+\d.]+)\s+Tf\z#', $window, $m)) {
                        $blockFontName = $m[1];
                        $blockFontSize = $m[2];
                        // Mirror to the cross-block running state so the
                        // next block sees the update too.
                        $activeFontName = $m[1];
                        $activeFontSize = $m[2];
                    }
                    $innerPos = $opPos + 2;
                    continue;
                }

                if ($opName === 'Tm') {
                    // Six floats preceding `Tm`. Last Tm in the block
                    // wins — Word emits exactly one per BT/ET, but the
                    // PDF spec permits multiple and last-wins matches
                    // what readers do.
                    $windowStart = max($innerPos, $opPos - 96);
                    $window = substr($stream, $windowStart, $opPos + 2 - $windowStart);
                    if (preg_match(
                        '#([-+\d.]+)\s+([-+\d.]+)\s+([-+\d.]+)\s+([-+\d.]+)\s+([-+\d.]+)\s+([-+\d.]+)\s+Tm\z#',
                        $window, $m
                    )) {
                        $tm = [
                            'a' => (float) $m[1], 'b' => (float) $m[2], 'c' => (float) $m[3],
                            'd' => (float) $m[4], 'e' => (float) $m[5], 'f' => (float) $m[6],
                        ];
                    }
                    $innerPos = $opPos + 2;
                    continue;
                }

                if ($opName === 'TJ') {
                    // Find `]` immediately preceding TJ (skip whitespace
                    // per processTjArray's existing pattern), then the
                    // matching `[`. PDF 1.7 §9.4.3 forbids nested arrays
                    // inside a TJ operand so a depth-1 backward scan
                    // suffices.
                    $closeBracket = -1;
                    for ($i = $opPos - 1; $i >= $innerPos; $i--) {
                        $c = $stream[$i];
                        if (preg_match('/\s/', $c)) continue;
                        if ($c === ']') { $closeBracket = $i; break; }
                        break;
                    }
                    if ($closeBracket < 0) { $innerPos = $opPos + 2; continue; }

                    $openBracket = -1;
                    for ($i = $closeBracket - 1; $i >= $innerPos; $i--) {
                        if ($stream[$i] === '[') { $openBracket = $i; break; }
                        if ($stream[$i] === ']') break;
                    }
                    if ($openBracket < 0) { $innerPos = $opPos + 2; continue; }

                    $arrayContent = substr($stream, $openBracket + 1, $closeBracket - $openBracket - 1);
                    $entries = $this->parseTjArrayContent($arrayContent);
                    if ($entries !== null) {
                        $fontInfo = $blockFontName !== null ? ($pageFonts[$blockFontName] ?? null) : null;
                        $resolved = '';
                        foreach ($entries as $entry) {
                            if ($entry['kind'] !== 'text') continue;
                            $resolved .= $this->resolveOperandToUnicode(
                                $entry['bytes'], $entry['shape'], $fontInfo
                            );
                        }
                        $operators[] = [
                            'kind'          => 'TJ',
                            'op_offset'     => $opPos,
                            'operand_start' => $openBracket,
                            'operand_end'   => $closeBracket + 1,
                            'entries'       => $entries,
                            'resolved_text' => $resolved,
                        ];
                    }
                    $innerPos = $opPos + 2;
                    continue;
                }

                // Tj
                $operandInfo = $this->findTjOperand($stream, $opPos);
                if ($operandInfo !== null) {
                    [$operandStart, $operandEnd, $operandBytes, $shape] = $operandInfo;
                    $fontInfo = $blockFontName !== null ? ($pageFonts[$blockFontName] ?? null) : null;
                    $resolved = $this->resolveOperandToUnicode($operandBytes, $shape, $fontInfo);
                    $operators[] = [
                        'kind'          => 'Tj',
                        'op_offset'     => $opPos,
                        'operand_start' => $operandStart,
                        'operand_end'   => $operandEnd,
                        'entries'       => [[
                            'kind'  => 'text',
                            'bytes' => $operandBytes,
                            'shape' => $shape,
                        ]],
                        'resolved_text' => $resolved,
                    ];
                }
                $innerPos = $opPos + 2;
            }

            $blocks[] = [
                'bt_offset' => $btPos,
                'et_offset' => $etPos,
                'font_name' => $blockFontName,
                'font_size' => $blockFontSize,
                'tm'        => $tm,
                'operators' => $operators,
            ];

            $pos = $etPos + 2;
        }

        return $blocks;
    }

    /**
     * Scan [start, end) for `Tf` operators and update the by-reference
     * active-font name/size to the last Tf in the range. Used by
     * parseContentStreamModel to thread font state across BT/ET gaps.
     *
     * @param string  $stream
     * @param int     $start          Inclusive byte offset.
     * @param int     $end            Exclusive byte offset.
     * @param ?string $activeFontName By-reference; updated when a Tf is found.
     * @param ?string $activeFontSize By-reference; updated when a Tf is found.
     *
     * @return void
     *
     * @spec openspec/changes/feat-cross-bt-et-matching/design.md (D4)
     */
    private function applyTfInRange($stream, $start, $end, &$activeFontName, &$activeFontSize): void
    {
        $pos = $start;
        while ($pos < $end) {
            $tfPos = $this->findNextOperator($stream, $pos, 'Tf');
            if ($tfPos < 0 || $tfPos >= $end) return;
            $windowStart = max($pos, $tfPos - 64);
            $window = substr($stream, $windowStart, $tfPos + 2 - $windowStart);
            if (preg_match('#/([A-Za-z0-9_+\-]+)\s+([-+\d.]+)\s+Tf\z#', $window, $m)) {
                $activeFontName = $m[1];
                $activeFontSize = $m[2];
            }
            $pos = $tfPos + 2;
        }
    }

    /**
     * Phase 2 (cross-bt-et-matching): post-pass that finds needles whose
     * text spans multiple Tj/TJ operators within a single BT/ET text
     * object. The linear matcher in replaceInContentStream sees one
     * operator at a time and so misses these; this pass uses
     * parseContentStreamModel to build a per-block concatenated text
     * and matches across operator boundaries.
     *
     * Wired to run AFTER the linear matcher so it only handles what the
     * linear matcher could not. Cross-BT/ET matching (needles spanning
     * separate text objects) is reserved for Phase 3.
     *
     * Splice shape per match:
     *   - startOp's operand is truncated to its pre-match prefix
     *   - operators fully inside the match span are dropped
     *   - endOp's operand is truncated to its post-match suffix
     *   - placeholder is emitted inline between them as
     *     `/F-fb-anonym 10 Tf (placeholder) Tj /<font> <size> Tf`
     *     (or q/Q-wrapped when the active font/size are unknown)
     *
     * Safe-splice guard: a match start or end that lands in the
     * interior of a non-literal entry (hex/CID where one byte may
     * resolve to multiple unicode codepoints) is rejected — the match
     * is skipped rather than mis-spliced.
     *
     * @param string $stream        Decoded content stream bytes (post-linear-pass).
     * @param array  $substitutions needle → placeholder
     * @param array  $pageFonts     Font resource map for the page.
     * @param ?int   $pageOid       Page object id (for fallback font injection).
     * @param int    $oid           Object id (diagnostic).
     * @param array  $stats         Diagnostic accumulator (by-ref).
     *
     * @return string Possibly-modified stream bytes.
     *
     * @spec openspec/changes/feat-cross-bt-et-matching/design.md (D2, D6)
     */
    private function applyCrossOperatorReplacements(
        string $stream, array $substitutions, array $pageFonts,
        $pageOid, int $oid, array &$stats
    ): string {
        if (count($substitutions) === 0) return $stream;
        if (strpos($stream, 'BT') === false) return $stream;

        $blocks = $this->parseContentStreamModel($stream, $pageFonts);
        if (empty($blocks)) return $stream;

        // Collect edits as {start, end, replacement}. Applied in REVERSE
        // offset order at the end so earlier offsets stay valid.
        $edits = [];

        foreach ($blocks as $block) {
            if (count($block['operators']) < 2) continue;

            $fontInfo = $block['font_name'] !== null
                ? ($pageFonts[$block['font_name']] ?? null)
                : null;

            // Build per-op text spans in the concatenated block text.
            $concat = '';
            $opSpans = [];  // opIdx => [start, end] in text-byte offsets
            foreach ($block['operators'] as $opIdx => $op) {
                $opStart = strlen($concat);
                $concat .= $op['resolved_text'];
                $opSpans[$opIdx] = ['start' => $opStart, 'end' => strlen($concat)];
            }
            if ($concat === '') continue;

            foreach ($substitutions as $needle => $placeholder) {
                if ($needle === '') continue;
                if (strlen($needle) > strlen($concat)) continue;

                $searchFrom = 0;
                while (true) {
                    $matchPos = strpos($concat, $needle, $searchFrom);
                    if ($matchPos === false) break;
                    $matchEnd = $matchPos + strlen($needle);

                    // Identify start/end operators
                    $startOpIdx = null;
                    $endOpIdx = null;
                    foreach ($opSpans as $idx => $span) {
                        if ($startOpIdx === null
                            && $matchPos >= $span['start']
                            && $matchPos < $span['end']) {
                            $startOpIdx = $idx;
                        }
                        if ($matchEnd > $span['start']
                            && $matchEnd <= $span['end']) {
                            $endOpIdx = $idx;
                            break;
                        }
                    }
                    if ($startOpIdx === null || $endOpIdx === null) {
                        // Should not happen for an in-range match; defensive.
                        $searchFrom = $matchPos + 1;
                        continue;
                    }
                    if ($startOpIdx === $endOpIdx) {
                        // Single-operator match — handled by the linear
                        // matcher upstream; nothing for cross-op to do.
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    $edit = $this->buildCrossOpEdit(
                        $block, $startOpIdx, $matchPos - $opSpans[$startOpIdx]['start'],
                        $endOpIdx, $matchEnd - $opSpans[$endOpIdx]['start'],
                        $placeholder, $fontInfo, $pageOid, $stats
                    );

                    if ($edit === null) {
                        // Skipped — entry interior on a non-literal, or
                        // fallback unavailable. Move past this position
                        // so we don't loop on the same match.
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    $edits[] = $edit;
                    if (!isset($stats['replacements_per_needle'][$needle])) {
                        $stats['replacements_per_needle'][$needle] = 0;
                    }
                    $stats['replacements_per_needle'][$needle]++;
                    if (!isset($stats['cross_operator_matches'])) {
                        $stats['cross_operator_matches'] = 0;
                    }
                    $stats['cross_operator_matches']++;

                    // Continue scanning past the match. The edit list
                    // is byte-range based and we don't mutate $concat,
                    // so subsequent matches against $concat use the
                    // pre-edit string — fine because each match yields
                    // its own non-overlapping byte-range edit.
                    $searchFrom = $matchEnd;
                }
            }
        }

        if (empty($edits)) return $stream;

        // Apply edits in REVERSE offset order so earlier offsets remain
        // valid across the substr_replace.
        usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
        foreach ($edits as $e) {
            $stream = substr($stream, 0, $e['start'])
                . $e['replacement']
                . substr($stream, $e['end']);
        }
        return $stream;
    }

    /**
     * Build a single byte-range edit for a cross-operator match within
     * one BT/ET block. The edit replaces bytes [startOp.operand_start,
     * endOp.op_offset+2) with the new operator sequence.
     *
     * Returns null when the match can't be safely spliced (non-literal
     * entry interior, missing fallback resource, missing pageOid).
     *
     * @param array  $block             BTBlock from parseContentStreamModel
     * @param int    $startOpIdx        Index of the operator containing match start
     * @param int    $startInResolved   Byte offset of match start within startOp.resolved_text
     * @param int    $endOpIdx          Index of the operator containing match end
     * @param int    $endInResolved     Byte offset of match end within endOp.resolved_text
     * @param string $placeholder       UTF-8 placeholder text (e.g. "[PERSON: 3]")
     * @param ?array $fontInfo          Active font info for the block
     * @param ?int   $pageOid           Page object id
     * @param array  $stats             Diagnostic accumulator (by-ref)
     *
     * @return array{start:int,end:int,replacement:string}|null
     */
    private function buildCrossOpEdit(
        array $block, int $startOpIdx, int $startInResolved,
        int $endOpIdx, int $endInResolved,
        string $placeholder, $fontInfo,
        $pageOid, array &$stats
    ): ?array {
        $startOp = $block['operators'][$startOpIdx];
        $endOp   = $block['operators'][$endOpIdx];

        // Resolve text-offset → (entry, byte-in-entry) for both ends.
        $startInfo = $this->resolvedOffsetToEntryByte($startOp['entries'], $fontInfo, $startInResolved);
        $endInfo   = $this->resolvedOffsetToEntryByte($endOp['entries'],   $fontInfo, $endInResolved);
        if ($startInfo === null || $endInfo === null) {
            return null;
        }

        // Compute the surviving prefix entries for startOp and suffix
        // entries for endOp.
        $prefixEntries = $this->keepEntryPrefix($startOp['entries'], $startInfo);
        $suffixEntries = $this->keepEntrySuffix($endOp['entries'],   $endInfo);

        // Encode the placeholder via the Helvetica fallback. Cross-op
        // splices always use the fallback path (same rationale as the
        // intra-block emission: subset-font glyph coverage is unreliable
        // and the fallback gives us the bracket/digit/punct guarantees).
        if ($pageOid === null) return null;
        $fallbackBytes = $this->encodeViaFallback($placeholder);
        if ($fallbackBytes === null) return null;
        $fallbackResource = $this->injectFallbackFontResource($pageOid);
        if ($fallbackResource === null) return null;

        // Assemble the replacement: [prefix-operand prefix-op] placeholder [suffix-operand suffix-op]
        $parts = [];

        if (!empty($prefixEntries)) {
            $parts[] = $this->renderEntriesAsOperand($prefixEntries, $startOp['kind']);
            $parts[] = $startOp['kind'];
        }

        // Placeholder Tj — Tf-restore shape when active font is known,
        // q/Q wrap otherwise. Matches the existing intra-block emission
        // contract introduced in commit 3eb1487.
        if ($block['font_name'] !== null && $block['font_size'] !== null) {
            $parts[] = $fallbackResource . ' 10 Tf ('
                . $this->escapePdfLiteral($fallbackBytes) . ') Tj /'
                . $block['font_name'] . ' ' . $block['font_size'] . ' Tf';
        } else {
            $parts[] = 'q ' . $fallbackResource . ' 10 Tf ('
                . $this->escapePdfLiteral($fallbackBytes) . ') Tj Q';
        }

        if (!empty($suffixEntries)) {
            $parts[] = $this->renderEntriesAsOperand($suffixEntries, $endOp['kind']);
            $parts[] = $endOp['kind'];
        }

        $stats['subset_font_fallbacks_used']++;

        return [
            'start'       => $startOp['operand_start'],
            'end'         => $endOp['op_offset'] + 2,
            'replacement' => implode(' ', $parts),
        ];
    }

    /**
     * Translate a text-byte offset within an operator's resolved_text
     * to (entry index, byte offset within that entry's raw bytes).
     *
     * Returns null when the offset lands in the interior of an entry
     * whose raw bytes don't map 1:1 to resolved-text bytes — i.e. hex
     * fragments or simple-font fragments with multi-byte UTF-8 codepoints
     * resulting from the active encoding. The caller treats null as
     * "skip the match" (same guard as the intra-TJ literal-split logic
     * in processTjArray).
     *
     * Boundary offsets (`textOffset === sum of preceding entry text
     * lengths`) are always allowed and reported with byte_in_entry=0
     * or =strlen(entry.bytes), letting the caller keep/drop the whole
     * entry without splitting.
     *
     * @param array  $entries     Operator entries (parseTjArrayContent shape).
     * @param ?array $fontInfo    Active font info; null = ASCII passthrough.
     * @param int    $textOffset  Byte offset within the resolved text.
     *
     * @return array{entry_idx:int,byte_in_entry:int}|null
     */
    private function resolvedOffsetToEntryByte(array $entries, $fontInfo, int $textOffset): ?array
    {
        $accum = 0;
        foreach ($entries as $idx => $entry) {
            if ($entry['kind'] !== 'text') continue;
            $resolved = $this->resolveOperandToUnicode(
                $entry['bytes'], $entry['shape'], $fontInfo
            );
            $entryTextLen = strlen($resolved);

            if ($textOffset === $accum) {
                return ['entry_idx' => $idx, 'byte_in_entry' => 0];
            }
            if ($textOffset < $accum + $entryTextLen) {
                // Interior of this entry. Safe to split only if the
                // entry is literal-shape AND raw bytes map 1:1 to
                // resolved bytes (no encoding expansion).
                if ($entry['shape'] !== 'literal'
                    || strlen($entry['bytes']) !== $entryTextLen) {
                    return null;
                }
                return [
                    'entry_idx'     => $idx,
                    'byte_in_entry' => $textOffset - $accum,
                ];
            }
            if ($textOffset === $accum + $entryTextLen) {
                return [
                    'entry_idx'     => $idx,
                    'byte_in_entry' => strlen($entry['bytes']),
                ];
            }
            $accum += $entryTextLen;
        }
        return null;
    }

    /**
     * Return the subset of entries that lies BEFORE a cut point — the
     * cut entry is truncated to its head bytes. Used when splicing the
     * pre-match prefix of an operator's operand.
     *
     * @param array $entries Original entries.
     * @param array $cut     {entry_idx, byte_in_entry} from resolvedOffsetToEntryByte.
     *
     * @return array<int, array> Surviving entries (possibly empty).
     */
    private function keepEntryPrefix(array $entries, array $cut): array
    {
        $idx = $cut['entry_idx'];
        $byteInEntry = $cut['byte_in_entry'];

        $kept = [];
        foreach ($entries as $i => $entry) {
            if ($i < $idx) {
                $kept[] = $entry;
                continue;
            }
            if ($i === $idx && $entry['kind'] === 'text' && $byteInEntry > 0) {
                $kept[] = [
                    'kind'  => 'text',
                    'bytes' => substr($entry['bytes'], 0, $byteInEntry),
                    'shape' => $entry['shape'],
                ];
            }
            // Drop entries at or after the cut (or entries inside the
            // match range but BEFORE byte_in_entry=0 boundary).
            break;
        }
        // Trim trailing kerning — kerning at the splice boundary would
        // adjust the (now-deleted) next text's advance; no visual
        // effect after the cut.
        while (!empty($kept) && end($kept)['kind'] === 'kern') {
            array_pop($kept);
        }
        return $kept;
    }

    /**
     * Symmetric to keepEntryPrefix: return the subset of entries that
     * lies AFTER the cut point — the cut entry is truncated to its tail
     * bytes. Used when splicing the post-match suffix of an operator's
     * operand.
     *
     * @param array $entries Original entries.
     * @param array $cut     {entry_idx, byte_in_entry} from resolvedOffsetToEntryByte.
     *
     * @return array<int, array> Surviving entries (possibly empty).
     */
    private function keepEntrySuffix(array $entries, array $cut): array
    {
        $idx = $cut['entry_idx'];
        $byteInEntry = $cut['byte_in_entry'];

        $kept = [];
        $started = false;
        foreach ($entries as $i => $entry) {
            if ($i < $idx) continue;
            if ($i === $idx) {
                if ($entry['kind'] === 'text'
                    && $byteInEntry < strlen($entry['bytes'])) {
                    $kept[] = [
                        'kind'  => 'text',
                        'bytes' => substr($entry['bytes'], $byteInEntry),
                        'shape' => $entry['shape'],
                    ];
                }
                $started = true;
                continue;
            }
            if ($started) {
                $kept[] = $entry;
            }
        }
        // Trim leading kerning — adjusts advance of "the next text"
        // i.e. the placeholder, which is independently positioned.
        while (!empty($kept) && $kept[0]['kind'] === 'kern') {
            array_shift($kept);
        }
        return $kept;
    }

    /**
     * Render a list of entries back to the operand-bytes for a Tj or
     * TJ operator. For TJ we reuse renderTjArray; for Tj we build a
     * single `(literal)` or `<hex>` operand from the single entry.
     *
     * @param array  $entries Entries to render (may be empty).
     * @param string $kind    'Tj' or 'TJ' — original operator kind.
     *
     * @return string Operand-bytes (e.g. `[(foo) -3 (bar)]` for TJ).
     */
    private function renderEntriesAsOperand(array $entries, string $kind): string
    {
        if ($kind === 'TJ') {
            return $this->renderTjArray($entries);
        }
        // Tj: expect at most one text entry.
        if (empty($entries)) return '()';
        $entry = $entries[0];
        return $this->renderTjFragment($entry);
    }

    /**
     * Phase 3 (cross-bt-et-matching): post-pass that finds needles whose
     * text spans multiple BT/ET text objects within a single content
     * stream. Builds on Phase 1's parsed model and Phase 2's splice
     * primitives.
     *
     * Two-step process:
     *   1. Group BT/ET blocks into logical lines via
     *      groupBlocksIntoLogicalLines (same Y within ε=0.5, same font
     *      resource + size, monotonic X with gap < 8× font size).
     *   2. For each logical line, concatenate the operators' resolved
     *      text and search for substitutions. For matches that span
     *      two blocks, splice via buildCrossBlockEdit.
     *
     * v1 restriction: the start operator must be the LAST operator in
     * its block AND the end operator must be the FIRST operator in
     * its block. This covers the dominant real-world shapes
     * (tagged-PDF /Span per BT/ET, Word table-cell character-per-BT/ET)
     * without the complexity of dropping intra-block operators that
     * lie outside the match span. Multi-op-block matches bump
     * `cross_block_skipped_multi_op_block` for future investigation.
     *
     * @param string $stream        Decoded content stream (post Phase 2).
     * @param array  $substitutions needle → placeholder
     * @param array  $pageFonts     Font resource map for the page.
     * @param ?int   $pageOid       Page object id (for fallback injection).
     * @param int    $oid           Object id (diagnostic).
     * @param array  $stats         Diagnostic accumulator (by-ref).
     *
     * @return string Possibly-modified stream bytes.
     *
     * @spec openspec/changes/feat-cross-bt-et-matching/design.md (D6, D7, D8)
     */
    private function applyCrossBlockReplacements(
        string $stream, array $substitutions, array $pageFonts,
        $pageOid, int $oid, array &$stats
    ): string {
        if (count($substitutions) === 0) return $stream;
        if (strpos($stream, 'BT') === false) return $stream;

        $blocks = $this->parseContentStreamModel($stream, $pageFonts);
        if (count($blocks) < 2) return $stream;

        $lines = $this->groupBlocksIntoLogicalLines($blocks, $stats);
        if (empty($lines)) return $stream;

        // Collect edits; apply in reverse offset order.
        $edits = [];

        foreach ($lines as $line) {
            if (count($line) < 2) continue;

            // Concatenate the line's resolved text and remember each
            // operator's span in concat-text coordinates.
            $concat = '';
            $opIndex = [];   // ordered list of [blockIdx, opIdx, textStart, textEnd]
            foreach ($line as $blockIdx) {
                $block = $blocks[$blockIdx];
                foreach ($block['operators'] as $opIdx => $op) {
                    $opStart = strlen($concat);
                    $concat .= $op['resolved_text'];
                    $opIndex[] = [
                        'block_idx'  => $blockIdx,
                        'op_idx'     => $opIdx,
                        'text_start' => $opStart,
                        'text_end'   => strlen($concat),
                    ];
                }
            }
            if ($concat === '') continue;

            foreach ($substitutions as $needle => $placeholder) {
                if ($needle === '' || strlen($needle) > strlen($concat)) continue;

                $searchFrom = 0;
                while (true) {
                    $matchPos = strpos($concat, $needle, $searchFrom);
                    if ($matchPos === false) break;
                    $matchEnd = $matchPos + strlen($needle);

                    // Locate start + end ops in $opIndex.
                    $startEntry = null;
                    $endEntry   = null;
                    foreach ($opIndex as $entry) {
                        if ($startEntry === null
                            && $matchPos >= $entry['text_start']
                            && $matchPos < $entry['text_end']) {
                            $startEntry = $entry;
                        }
                        if ($matchEnd > $entry['text_start']
                            && $matchEnd <= $entry['text_end']) {
                            $endEntry = $entry;
                            break;
                        }
                    }
                    if ($startEntry === null || $endEntry === null) {
                        $searchFrom = $matchPos + 1;
                        continue;
                    }
                    if ($startEntry['block_idx'] === $endEntry['block_idx']) {
                        // Same block — Phase 2 already handled it (or
                        // the linear matcher did). Phase 3 only adds
                        // cross-BT/ET coverage.
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    // v1 restriction: start op must be last in its block,
                    // end op must be first in its block.
                    $startBlock = $blocks[$startEntry['block_idx']];
                    $endBlock   = $blocks[$endEntry['block_idx']];
                    if ($startEntry['op_idx'] !== count($startBlock['operators']) - 1
                        || $endEntry['op_idx'] !== 0) {
                        if (!isset($stats['cross_block_skipped_multi_op_block'])) {
                            $stats['cross_block_skipped_multi_op_block'] = 0;
                        }
                        $stats['cross_block_skipped_multi_op_block']++;
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    $edit = $this->buildCrossBlockEdit(
                        $stream,
                        $startBlock, $startEntry['op_idx'],
                        $matchPos - $startEntry['text_start'],
                        $endBlock, $endEntry['op_idx'],
                        $matchEnd - $endEntry['text_start'],
                        $placeholder, $pageFonts, $pageOid, $stats
                    );

                    if ($edit === null) {
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    $edits[] = $edit;
                    if (!isset($stats['replacements_per_needle'][$needle])) {
                        $stats['replacements_per_needle'][$needle] = 0;
                    }
                    $stats['replacements_per_needle'][$needle]++;
                    if (!isset($stats['cross_block_matches'])) {
                        $stats['cross_block_matches'] = 0;
                    }
                    $stats['cross_block_matches']++;

                    $searchFrom = $matchEnd;
                }
            }
        }

        if (empty($edits)) return $stream;

        // Apply edits in reverse offset order so earlier offsets stay
        // valid. Cross-block edits cover much wider byte ranges than
        // intra-block ones; an overlap between two cross-block edits
        // on the same span would be malformed — skip the second when
        // detected.
        usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
        $lastStart = PHP_INT_MAX;
        foreach ($edits as $e) {
            if ($e['end'] > $lastStart) {
                // Overlap with a more-recent edit; skip to avoid
                // double-mutating the same range.
                continue;
            }
            $stream = substr($stream, 0, $e['start'])
                . $e['replacement']
                . substr($stream, $e['end']);
            $lastStart = $e['start'];
        }
        return $stream;
    }

    /**
     * Group parsed BT/ET blocks into logical lines for cross-block
     * matching: same font name, same font size, same Y (within ε=0.5),
     * identity-axes (a=d=1, b=c=0), monotonic X without gaps wider
     * than 8 × font_size. Each returned line is an ordered list of
     * block indices, left-to-right.
     *
     * The X-gap threshold guards multi-column layouts: two columns at
     * the same Y baseline must not be aggregated into one line. The
     * 8× heuristic is field-tunable (see design D8).
     *
     * @param array $blocks parseContentStreamModel output.
     * @param array $stats  Diagnostic accumulator (by-ref).
     *
     * @return array<int, array<int, int>> List of lines, each a list of block indices.
     *
     * @spec openspec/changes/feat-cross-bt-et-matching/design.md (D8)
     */
    private function groupBlocksIntoLogicalLines(array $blocks, array &$stats): array
    {
        // Filter eligible blocks: must have at least one operator, a
        // known font, identity axes (no rotation/skew), and a
        // non-degenerate scale.
        $eligible = [];
        foreach ($blocks as $idx => $b) {
            if (count($b['operators']) === 0) continue;
            if ($b['font_name'] === null) continue;
            if (abs($b['tm']['b']) > 0.001 || abs($b['tm']['c']) > 0.001) continue;
            if ($b['tm']['a'] <= 0 || $b['tm']['d'] <= 0) continue;
            $eligible[] = $idx;
        }

        // Bucket by (font_name, font_size). Each bucket's blocks share
        // the same font; logical-line grouping then partitions by Y.
        $fontBuckets = [];
        foreach ($eligible as $idx) {
            $b = $blocks[$idx];
            $key = $b['font_name'] . '|' . (string) $b['font_size'];
            $fontBuckets[$key][] = $idx;
        }

        $lines = [];
        foreach ($fontBuckets as $bucket) {
            if (count($bucket) < 2) continue;

            // Sort within each bucket: by Y descending (top-of-page
            // first), then X ascending within same Y.
            usort($bucket, function ($a, $b) use ($blocks) {
                $dy = $blocks[$b]['tm']['f'] - $blocks[$a]['tm']['f'];
                if (abs($dy) > 0.5) return $dy > 0 ? 1 : -1;
                return $blocks[$a]['tm']['e'] <=> $blocks[$b]['tm']['e'];
            });

            // Walk the sorted list: gather runs of same-Y blocks; for
            // each run, split into logical lines on X-gap > threshold.
            $i = 0;
            while ($i < count($bucket)) {
                $sameY = [$bucket[$i]];
                $j = $i + 1;
                $baseY = $blocks[$bucket[$i]]['tm']['f'];
                while ($j < count($bucket)
                    && abs($blocks[$bucket[$j]]['tm']['f'] - $baseY) <= 0.5) {
                    $sameY[] = $bucket[$j];
                    $j++;
                }
                if (count($sameY) >= 2) {
                    // Same-Y + same-font blocks form one logical line.
                    // We deliberately do NOT split on X-gap: estimating
                    // each block's actual rendered width without per-
                    // glyph font metrics produces large errors,
                    // especially after a previous pass inserts
                    // placeholders rendered in a different font/size.
                    // A wrong gap estimate would either under-split
                    // (producing false joins) or over-split (missing
                    // genuine cross-block needles like the dominant
                    // tagged-PDF span case). For single-column
                    // letter-shaped documents (Dutch government, body
                    // text) over-aggregation is benign — placeholders
                    // emitted at the wrong X within a line look
                    // misaligned but the text-stream is still valid.
                    // Multi-column layouts will need a future
                    // heuristic (per-glyph metrics or a tab-stop
                    // detector); not in this phase.
                    $lines[] = $sameY;
                }
                $i = $j;
            }
        }

        if (!isset($stats['logical_lines_built'])) $stats['logical_lines_built'] = 0;
        $stats['logical_lines_built'] += count($lines);
        return $lines;
    }

    /**
     * Build the byte-range edit for a cross-block match. The edit
     * covers [startOp.operand_start, endOp.op_offset+2) and emits:
     *
     *   prefix-operand + startOp-kind           (if non-empty prefix)
     *   placeholder Tj + Tf-restore
     *   ET                                       (close start block)
     *   <endBlock.bt_offset .. endOp.operand_start>  (re-emit end block setup)
     *   suffix-operand + endOp-kind             (if non-empty suffix)
     *
     * The original ET of startBlock and BT of endBlock are inside the
     * edit range; we re-emit "ET" and the endBlock setup verbatim. The
     * surviving suffix of endBlock (operators after endOp + the
     * original ET) lies AFTER the edit range and is preserved.
     *
     * Returns null when the splice can't safely run (entry-interior
     * split on a non-literal entry; no fallback resource available).
     */
    private function buildCrossBlockEdit(
        string $stream,
        array $startBlock, int $startOpIdx, int $startInResolved,
        array $endBlock,   int $endOpIdx,   int $endInResolved,
        string $placeholder, array $pageFonts,
        $pageOid, array &$stats,
        bool $preserveInterBlockBytes = false
    ): ?array {
        $startOp = $startBlock['operators'][$startOpIdx];
        $endOp   = $endBlock['operators'][$endOpIdx];

        $startFontInfo = $startBlock['font_name'] !== null
            ? ($pageFonts[$startBlock['font_name']] ?? null)
            : null;
        $endFontInfo = $endBlock['font_name'] !== null
            ? ($pageFonts[$endBlock['font_name']] ?? null)
            : null;

        $startInfo = $this->resolvedOffsetToEntryByte($startOp['entries'], $startFontInfo, $startInResolved);
        $endInfo   = $this->resolvedOffsetToEntryByte($endOp['entries'],   $endFontInfo,   $endInResolved);
        if ($startInfo === null || $endInfo === null) return null;

        $prefixEntries = $this->keepEntryPrefix($startOp['entries'], $startInfo);
        $suffixEntries = $this->keepEntrySuffix($endOp['entries'],   $endInfo);

        if ($pageOid === null) return null;
        $fallbackBytes = $this->encodeViaFallback($placeholder);
        if ($fallbackBytes === null) return null;
        $fallbackResource = $this->injectFallbackFontResource($pageOid);
        if ($fallbackResource === null) return null;

        $parts = [];

        if (!empty($prefixEntries)) {
            $parts[] = $this->renderEntriesAsOperand($prefixEntries, $startOp['kind']);
            $parts[] = $startOp['kind'];
        }

        // Placeholder Tj + Tf-restore using startBlock's font.
        if ($startBlock['font_name'] !== null && $startBlock['font_size'] !== null) {
            $parts[] = $fallbackResource . ' 10 Tf ('
                . $this->escapePdfLiteral($fallbackBytes) . ') Tj /'
                . $startBlock['font_name'] . ' ' . $startBlock['font_size'] . ' Tf';
        } else {
            $parts[] = 'q ' . $fallbackResource . ' 10 Tf ('
                . $this->escapePdfLiteral($fallbackBytes) . ') Tj Q';
        }

        // Close start block.
        $parts[] = 'ET';

        // Phase 4 (cross-line-matching): the byte range between the
        // start block's ET and the end block's BT can carry graphics
        // state the rest of the page depends on (colour ops, q/Q,
        // re/W clip paths between paragraphs). Cross-line edits span
        // whole-line distances where dropping these is observable, so
        // re-emit the range verbatim. Cross-block (same-line) edits
        // keep the historical drop behaviour — tagged-PDF span splits
        // are byte-adjacent and have nothing in the gap.
        if ($preserveInterBlockBytes === true) {
            $interStart = $startBlock['et_offset'] + 2;
            $interLen = $endBlock['bt_offset'] - $interStart;
            if ($interLen > 0) {
                $parts[] = substr($stream, $interStart, $interLen);
            }
        }

        // Re-emit end block's BT and setup (Tm, Tf, etc.) — bytes
        // from endBlock.bt_offset to endOp.operand_start. Includes
        // the literal "BT" token.
        $endSetup = substr(
            $stream,
            $endBlock['bt_offset'],
            $endOp['operand_start'] - $endBlock['bt_offset']
        );
        $parts[] = $endSetup;

        // Suffix operand + end op (if non-empty). When empty, the
        // surviving content after endOp.op_offset+2 (operators after
        // endOp + endBlock's original ET) carries everything; we
        // still emit a valid (empty) text object via the endSetup
        // bytes alone.
        if (!empty($suffixEntries)) {
            $parts[] = $this->renderEntriesAsOperand($suffixEntries, $endOp['kind']);
            $parts[] = $endOp['kind'];
        }

        $stats['subset_font_fallbacks_used']++;

        return [
            'start'       => $startOp['operand_start'],
            'end'         => $endOp['op_offset'] + 2,
            'replacement' => implode(' ', $parts),
        ];
    }

    /**
     * Phase 4 (cross-line-matching): post-pass that finds needles whose
     * text wraps across a visual LINE break — "14 mei" at the end of one
     * line and "2026" at the start of the next. Phases 1-3 only match
     * within a logical line (same Y); this pass pairs vertically
     * adjacent same-font blocks and matches across the boundary.
     *
     * The wrap point represents whitespace: producers either keep the
     * trailing space on the top line ("14 mei " + "2026", Word's shape)
     * or drop it entirely ("14 mei" + "2026"). Concatenation handles
     * the former directly; for the latter a synthetic space is inserted
     * into the match view and a match is only accepted when the needle
     * has a literal space at that position (the synthetic char never
     * maps to operand bytes — it lies strictly between the two ops, so
     * the splice machinery never sees it).
     *
     * v1 restrictions (mirror Phase 3): the match must start in the
     * LAST operator of the top block and end in the FIRST operator of
     * the bottom block, and the pair must be stream-ordered
     * (bottom.bt_offset > top.et_offset). Needles spanning three or
     * more lines are out of scope.
     *
     * @param string $stream        Decoded content stream bytes (post-Phase-3).
     * @param array  $substitutions needle → placeholder
     * @param array  $pageFonts     Font resource map for the page.
     * @param ?int   $pageOid       Page object id (for fallback font injection).
     * @param int    $oid           Object id (diagnostic).
     * @param array  $stats         Diagnostic accumulator (by-ref).
     *
     * @return string Possibly-modified stream bytes.
     *
     * @spec openspec/changes/feat-cross-line-matching/design.md
     */
    private function applyCrossLineReplacements(
        string $stream, array $substitutions, array $pageFonts,
        $pageOid, int $oid, array &$stats
    ): string {
        if (count($substitutions) === 0) return $stream;
        if (strpos($stream, 'BT') === false) return $stream;

        $blocks = $this->parseContentStreamModel($stream, $pageFonts);
        if (count($blocks) < 2) return $stream;

        $pairs = $this->groupBlocksIntoLinePairs($blocks, $stats);
        if (empty($pairs)) return $stream;

        $edits = [];

        foreach ($pairs as [$topIdx, $bottomIdx]) {
            $topBlock    = $blocks[$topIdx];
            $bottomBlock = $blocks[$bottomIdx];

            // Stream-order guard: the edit re-emits bytes between the
            // top block's ET and the bottom block's BT; a pair whose
            // stream order disagrees with its visual order can't be
            // spliced as one contiguous range.
            if ($bottomBlock['bt_offset'] <= $topBlock['et_offset']) {
                continue;
            }

            // Concatenated match view across the pair, with per-op
            // spans in concat coordinates (same shape as Phase 3).
            $concat = '';
            $opIndex = [];
            $syntheticPos = -1;
            foreach ([$topIdx, $bottomIdx] as $blockIdx) {
                if ($blockIdx === $bottomIdx) {
                    // Line-break = whitespace. Insert a synthetic space
                    // only when the top line didn't keep its trailing
                    // space and the bottom line doesn't lead with one.
                    if ($concat !== '' && !preg_match('/\s$/', $concat)) {
                        $bottomFirst = $blocks[$bottomIdx]['operators'][0]['resolved_text'] ?? '';
                        if ($bottomFirst === '' || !preg_match('/^\s/', $bottomFirst)) {
                            $syntheticPos = strlen($concat);
                            $concat .= ' ';
                        }
                    }
                }
                foreach ($blocks[$blockIdx]['operators'] as $opIdx => $op) {
                    $opStart = strlen($concat);
                    $concat .= $op['resolved_text'];
                    $opIndex[] = [
                        'block_idx'  => $blockIdx,
                        'op_idx'     => $opIdx,
                        'text_start' => $opStart,
                        'text_end'   => strlen($concat),
                    ];
                }
            }
            if ($concat === '') continue;

            // Ranges already claimed by an accepted match, in concat
            // coordinates. Overlapping needles ("4 mei 2026" inside
            // "14 mei 2026") otherwise both build edits over the SAME
            // byte range; the applier would keep only one — leaving
            // replacements_per_needle over-counted and the winner
            // dependent on map order rather than on which needle
            // actually covers the text.
            $claimed = [];

            foreach ($substitutions as $needle => $placeholder) {
                if ($needle === '' || strlen($needle) > strlen($concat)) continue;

                $searchFrom = 0;
                while (true) {
                    $matchPos = strpos($concat, $needle, $searchFrom);
                    if ($matchPos === false) break;
                    $matchEnd = $matchPos + strlen($needle);

                    // Skip matches overlapping an already-claimed range.
                    $overlapsClaimed = false;
                    foreach ($claimed as [$cStart, $cEnd]) {
                        if ($matchPos < $cEnd && $matchEnd > $cStart) {
                            $overlapsClaimed = true;
                            break;
                        }
                    }
                    if ($overlapsClaimed === true) {
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    // A synthetic space inside the match must line up
                    // with a literal space in the needle — it stands in
                    // for the wrap, not for arbitrary characters.
                    if ($syntheticPos >= 0
                        && $syntheticPos > $matchPos && $syntheticPos < $matchEnd
                        && $needle[$syntheticPos - $matchPos] !== ' ') {
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    $startEntry = null;
                    $endEntry   = null;
                    foreach ($opIndex as $entry) {
                        if ($startEntry === null
                            && $matchPos >= $entry['text_start']
                            && $matchPos < $entry['text_end']) {
                            $startEntry = $entry;
                        }
                        if ($matchEnd > $entry['text_start']
                            && $matchEnd <= $entry['text_end']) {
                            $endEntry = $entry;
                            break;
                        }
                    }
                    if ($startEntry === null || $endEntry === null) {
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    // Phase 4 only adds cross-LINE coverage: the match
                    // must genuinely span from the top block into the
                    // bottom block. Same-block residue is the earlier
                    // phases' territory (and their skip diagnostics).
                    if ($startEntry['block_idx'] !== $topIdx
                        || $endEntry['block_idx'] !== $bottomIdx) {
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    // v1 restriction: start op last in top block, end op
                    // first in bottom block (mirrors Phase 3).
                    if ($startEntry['op_idx'] !== count($topBlock['operators']) - 1
                        || $endEntry['op_idx'] !== 0) {
                        if (!isset($stats['cross_line_skipped_multi_op_block'])) {
                            $stats['cross_line_skipped_multi_op_block'] = 0;
                        }
                        $stats['cross_line_skipped_multi_op_block']++;
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    $edit = $this->buildCrossBlockEdit(
                        $stream,
                        $topBlock, $startEntry['op_idx'],
                        $matchPos - $startEntry['text_start'],
                        $bottomBlock, $endEntry['op_idx'],
                        $matchEnd - $endEntry['text_start'],
                        $placeholder, $pageFonts, $pageOid, $stats,
                        true
                    );

                    if ($edit === null) {
                        $searchFrom = $matchPos + 1;
                        continue;
                    }

                    $edits[] = $edit;
                    $claimed[] = [$matchPos, $matchEnd];
                    if (!isset($stats['replacements_per_needle'][$needle])) {
                        $stats['replacements_per_needle'][$needle] = 0;
                    }
                    $stats['replacements_per_needle'][$needle]++;
                    if (!isset($stats['cross_line_matches'])) {
                        $stats['cross_line_matches'] = 0;
                    }
                    $stats['cross_line_matches']++;

                    $searchFrom = $matchEnd;
                }
            }
        }

        if (empty($edits)) return $stream;

        // Apply edits in reverse offset order; skip overlaps (same
        // policy + rationale as the Phase 3 applier).
        usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
        $lastStart = PHP_INT_MAX;
        foreach ($edits as $e) {
            if ($e['end'] > $lastStart) {
                continue;
            }
            $stream = substr($stream, 0, $e['start'])
                . $e['replacement']
                . substr($stream, $e['end']);
            $lastStart = $e['start'];
        }
        return $stream;
    }

    /**
     * Pair vertically adjacent blocks for cross-line matching: same
     * font name + size, identity axes, top-to-bottom Y order with a
     * line gap in (0.5, 2.0 × font_size] — one line of leading, not a
     * paragraph break — and left margins within 1.5 × font_size of
     * each other (wrapped continuations return to the paragraph
     * margin; same-Y-range columns sit much further apart).
     *
     * When a visual line consists of several blocks (Phase 3's
     * logical-line case), the pair is built from the LAST block of the
     * top line and the FIRST block of the bottom line — the only two
     * blocks a wrapped needle can touch.
     *
     * @param array $blocks parseContentStreamModel output.
     * @param array $stats  Diagnostic accumulator (by-ref).
     *
     * @return array<int, array{0:int, 1:int}> List of [topIdx, bottomIdx] pairs.
     *
     * @spec openspec/changes/feat-cross-line-matching/design.md
     */
    private function groupBlocksIntoLinePairs(array $blocks, array &$stats): array
    {
        // Same eligibility as the Phase 3 grouper.
        $eligible = [];
        foreach ($blocks as $idx => $b) {
            if (count($b['operators']) === 0) continue;
            if ($b['font_name'] === null) continue;
            if (abs($b['tm']['b']) > 0.001 || abs($b['tm']['c']) > 0.001) continue;
            if ($b['tm']['a'] <= 0 || $b['tm']['d'] <= 0) continue;
            $eligible[] = $idx;
        }

        $fontBuckets = [];
        foreach ($eligible as $idx) {
            $b = $blocks[$idx];
            $key = $b['font_name'] . '|' . (string) $b['font_size'];
            $fontBuckets[$key][] = $idx;
        }

        $pairs = [];
        foreach ($fontBuckets as $bucket) {
            if (count($bucket) < 2) continue;

            usort($bucket, function ($a, $b) use ($blocks) {
                $dy = $blocks[$b]['tm']['f'] - $blocks[$a]['tm']['f'];
                if (abs($dy) > 0.5) return $dy > 0 ? 1 : -1;
                return $blocks[$a]['tm']['e'] <=> $blocks[$b]['tm']['e'];
            });

            // Collapse same-Y runs into visual lines (X-ascending).
            $visualLines = [];
            $i = 0;
            while ($i < count($bucket)) {
                $line = [$bucket[$i]];
                $j = $i + 1;
                $baseY = $blocks[$bucket[$i]]['tm']['f'];
                while ($j < count($bucket)
                    && abs($blocks[$bucket[$j]]['tm']['f'] - $baseY) <= 0.5) {
                    $line[] = $bucket[$j];
                    $j++;
                }
                $visualLines[] = $line;
                $i = $j;
            }

            // Pair consecutive lines whose gap is one leading step.
            for ($k = 0; $k + 1 < count($visualLines); $k++) {
                $topLine    = $visualLines[$k];
                $bottomLine = $visualLines[$k + 1];
                $topFirst    = $blocks[$topLine[0]];
                $bottomFirst = $blocks[$bottomLine[0]];

                $fontSize = (float) $topFirst['font_size'];
                if ($fontSize <= 0) continue;

                $gap = $topFirst['tm']['f'] - $bottomFirst['tm']['f'];
                if ($gap <= 0.5 || $gap > 2.0 * $fontSize) continue;

                if (abs($topFirst['tm']['e'] - $bottomFirst['tm']['e']) > 1.5 * $fontSize) {
                    continue;
                }

                $pairs[] = [end($topLine), $bottomLine[0]];
            }
        }

        if (!isset($stats['cross_line_pairs_built'])) $stats['cross_line_pairs_built'] = 0;
        $stats['cross_line_pairs_built'] += count($pairs);
        return $pairs;
    }

    /**
     * Build a per-content-stream font context by walking the page tree.
     *
     * For each page: resolve /Resources/Font[name] entries to font
     * dictionaries; parse each font's /ToUnicode (if present) into a
     * CMap; build a FontEncoding from /Encoding (or default to WinAnsi).
     *
     * @return array{streamToFonts: array<int, array>, streamToPage: array<int, int>, allFonts: array}
     */
    private function buildFontContext() {
        $allFonts = [];        // resource-name → encoding/cmap info (global merge)
        $streamToFonts = [];   // content-stream OID → page-specific font map
        $streamToPage = [];    // content-stream OID → page OID (for fallback font injection)
        $contentsArrayOids = []; // OIDs that are part of a /Contents array

        foreach ($this->get_object_iterator() as $oid => $obj) {
            $value = $obj->get_value();
            if (!isset($value['Type'])) continue;
            if ((string) $value['Type'] !== '/Page') continue;

            $pageFonts = $this->collectPageFonts($value);

            // Find this page's content stream OIDs via /Contents.
            // PDF 1.7 §7.8.2: /Contents can be a single stream reference
            // OR an array of references — in the array case, "the effect
            // shall be as if all of the streams in the array were
            // concatenated, in order, to form a single stream". Tf state
            // therefore crosses array entries. We don't concatenate
            // here (would require rebuilding /Contents); instead we
            // detect the array shape and surface it in the per-stream
            // context so the per-stream pass can record a diagnostic
            // (Tf-state-across-streams may be stale).
            $contentOids = [];
            $isArrayContents = false;
            if (isset($value['Contents'])) {
                $contentsValue = $value['Contents'];
                $refs = method_exists($contentsValue, 'get_object_referenced')
                    ? $contentsValue->get_object_referenced()
                    : false;
                if (is_array($refs)) {
                    $isArrayContents = (count($refs) > 1);
                    foreach ($refs as $ref) {
                        $contentOids[] = (int) $ref;
                    }
                } elseif (is_int($refs)
                    || (is_string($refs) && ctype_digit($refs))) {
                    // PDFValueSimple's `get_object_referenced()` returns
                    // the bare integer OID for a single `N 0 R`
                    // reference (the common Word-PDF shape) — neither
                    // `false` nor an array. The original branching
                    // missed this case, leaving streamToPage empty and
                    // making pageOid null for every content stream
                    // (which in turn prevented Helvetica-fallback
                    // injection for placeholders).
                    $contentOids[] = (int) $refs;
                } elseif ($refs === false) {
                    // Older PDFValueReference shape: resolve via
                    // get_indirect_object as a last resort.
                    $contentObj = $this->get_indirect_object($contentsValue);
                    if (is_object($contentObj) && method_exists($contentObj, 'get_oid')) {
                        $contentOids[] = $contentObj->get_oid();
                    }
                }
            }

            foreach ($contentOids as $cOid) {
                $streamToFonts[$cOid] = $pageFonts;
                $streamToPage[$cOid] = $oid;
                if ($isArrayContents) {
                    $contentsArrayOids[$cOid] = true;
                }
            }

            // Also merge into the global pool.
            foreach ($pageFonts as $name => $info) {
                if (!isset($allFonts[$name])) {
                    $allFonts[$name] = $info;
                }
            }
        }

        return [
            'streamToFonts'     => $streamToFonts,
            'streamToPage'      => $streamToPage,
            'allFonts'          => $allFonts,
            'contentsArrayOids' => $contentsArrayOids,
        ];
    }

    /**
     * Collect a page's font resource map.
     *
     * @param mixed $pageValue PDFValueObject for a /Page dictionary.
     *
     * @return array<string, array{encoding: \ddn\sapp\FontEncoding, cmap: ?\ddn\sapp\CMap, baseFont: string}>
     */
    private function collectPageFonts($pageValue) {
        $result = [];

        // PDF 1.7 §7.7.3.4: `/Resources` is inheritable from `/Pages`
        // parent nodes. Walk up via `/Parent` until we either find a
        // node with `/Resources` or run out of parents. Cap the walk
        // at 32 levels to bound malformed-PDF chains.
        $resources = null;
        $cursor = $pageValue;
        for ($depth = 0; $depth < 32; $depth++) {
            if (isset($cursor['Resources'])) {
                $resources = $cursor['Resources'];
                break;
            }
            if (!isset($cursor['Parent'])) {
                break;
            }
            $parentObj = $this->get_indirect_object($cursor['Parent']);
            if (!is_object($parentObj) || !method_exists($parentObj, 'get_value')) {
                break;
            }
            $cursor = $parentObj->get_value();
        }

        if ($resources === null) {
            return $result;
        }

        // Resources can be an inline dict or a reference; resolve.
        $resources = $this->resolveIndirectValue($resources);
        if (!isset($resources['Font'])) return $result;

        $fontDict = $this->resolveIndirectValue($resources['Font']);

        // PDFValueObject implements ArrayAccess; iterate its keys.
        if (method_exists($fontDict, 'get_keys')) {
            $keys = $fontDict->get_keys();
        } else {
            return $result;
        }

        // `get_keys()` can return false (e.g. an empty/unparsable /Font dict on
        // some chained-filter producers) — guard so the page simply contributes
        // no per-page fonts (the caller falls back to the document-wide font set)
        // instead of emitting a `foreach() argument must be of type array` warning.
        if (!is_array($keys)) {
            return $result;
        }

        foreach ($keys as $resourceName) {
            $fontRef = $fontDict[$resourceName];
            $fontObj = $this->get_indirect_object($fontRef);
            if (!is_object($fontObj) || !method_exists($fontObj, 'get_value')) {
                continue;
            }
            $info = $this->buildFontInfo($fontObj);
            if ($info !== null) {
                $result[$resourceName] = $info;
            }
        }
        return $result;
    }

    /**
     * Parse a Font object's /Encoding + /ToUnicode into an encoding/CMap pair.
     *
     * @param \ddn\sapp\PDFObject $fontObj Font dictionary object.
     *
     * @return array{encoding: \ddn\sapp\FontEncoding, cmap: ?\ddn\sapp\CMap, baseFont: string}|null
     */
    private function buildFontInfo($fontObj) {
        $fontValue = $fontObj->get_value();

        $baseFont = isset($fontValue['BaseFont']) ? (string) $fontValue['BaseFont'] : '(unknown)';

        // /Encoding may be a name (`/WinAnsiEncoding`) OR an inline
        // dictionary with `/BaseEncoding` + `/Differences [...]`. The
        // dict shape is spec-legal (PDF 1.7 §9.6.5.3) but `/Differences`
        // is NOT implemented here — record a typed diagnostic on
        // `encoding_dict_unhandled` so callers can detect the silent
        // degradation instead of getting empty glyph maps. We still try
        // to honour the inner `/BaseEncoding` for the fallback table.
        $encodingHandled = true;
        $encodingDictUnhandled = null;
        if (isset($fontValue['Encoding'])) {
            $encodingValue = $fontValue['Encoding'];
            if (is_a($encodingValue, 'ddn\\sapp\\pdfvalue\\PDFValueObject')
                || (is_object($encodingValue) && method_exists($encodingValue, 'get_keys')
                    && $encodingValue->get_keys() !== [])) {
                // Inline dict shape. Best-effort honour `/BaseEncoding`.
                $encodingHandled = false;
                $encodingDictUnhandled = $baseFont;
                $base = isset($encodingValue['BaseEncoding'])
                    ? (string) $encodingValue['BaseEncoding']
                    : '/WinAnsiEncoding';
                $encoding = FontEncoding::forName($base);
            } else {
                $encodingName = (string) $encodingValue;
                $encoding = FontEncoding::forName($encodingName);
            }
        } else {
            // PDF 1.7 §9.6.5.4: implicit default depends on font type and
            // the /FontDescriptor /Flags symbolic/nonsymbolic bit. For
            // Type1 fonts the default is /StandardEncoding; for
            // TrueType the default is /WinAnsiEncoding. We default to
            // /WinAnsiEncoding (the common case for nonsymbolic TT
            // fonts emitted by Word/LibreOffice/etc.); the residual
            // edge case is recorded indirectly via cid_split_mismatch
            // when resolution misfires. A typed `encoding_default_used`
            // diagnostic is a follow-up.
            $encoding = FontEncoding::forName('/WinAnsiEncoding');
        }

        // /ToUnicode is always an indirect reference to a stream object.
        $cmap = null;
        if (isset($fontValue['ToUnicode'])) {
            $toUnicodeObj = $this->get_indirect_object($fontValue['ToUnicode']);
            if (is_object($toUnicodeObj) && method_exists($toUnicodeObj, 'get_stream')) {
                $cmapBytes = $toUnicodeObj->get_stream(false);
                if (is_string($cmapBytes) && $cmapBytes !== '') {
                    $cmap = CMap::fromStream($cmapBytes);
                }
            }
        }

        return [
            'encoding'                 => $encoding,
            'cmap'                     => $cmap,
            'baseFont'                 => $baseFont,
            'encoding_dict_unhandled'  => $encodingDictUnhandled,
        ];
    }

    /**
     * Locate the next byte position in $stream where the named operator
     * appears as a whole token. Returns -1 when not found.
     *
     * @param string $stream Decoded content stream.
     * @param int    $start  Search start offset.
     * @param string $op     Operator name (e.g. 'Tj', 'Tf').
     *
     * @return int
     */
    private function findNextOperator($stream, $start, $op) {
        $len = strlen($stream);
        $opLen = strlen($op);
        $i = $start;

        // String-state-aware forward scan. PDF 1.7 §7.3.4: tokens like
        // `Tj` and `Tf` are operators only when they appear OUTSIDE
        // string literals (`(...)` with backslash escapes + nested
        // parens) and outside hex strings (`<...>`). A raw `strpos`
        // for `Tj` would match the substring inside `(Show Tj here)`
        // and the subsequent backward operand scan would corrupt the
        // stream. This tokenizer tracks string state so phantom
        // operators inside strings are ignored.
        while ($i < $len) {
            $c = $stream[$i];

            // Skip PDF comments (`%` to end-of-line).
            if ($c === '%') {
                $i++;
                while ($i < $len && $stream[$i] !== "\n" && $stream[$i] !== "\r") {
                    $i++;
                }
                continue;
            }

            // Skip literal strings `(...)` with backslash escapes +
            // nested paren counting.
            if ($c === '(') {
                $depth = 1;
                $i++;
                while ($i < $len && $depth > 0) {
                    $cc = $stream[$i];
                    if ($cc === '\\') {
                        // Backslash escape: consume the escape char too.
                        $i += 2;
                        continue;
                    }
                    if ($cc === '(') $depth++;
                    elseif ($cc === ')') $depth--;
                    $i++;
                }
                continue;
            }

            // Skip hex strings `<...>`. (Two-char `<<` and `>>` dict
            // delimiters never contain `Tj`/`Tf` substrings.)
            if ($c === '<' && $i + 1 < $len && $stream[$i + 1] !== '<') {
                $closing = strpos($stream, '>', $i + 1);
                if ($closing === false) {
                    return -1;
                }
                $i = $closing + 1;
                continue;
            }

            // Candidate token start: previous byte must be whitespace
            // (or BOF), and the substring at $i must equal $op, AND
            // the byte after must be whitespace / EOF.
            if ($i + $opLen <= $len && substr_compare($stream, $op, $i, $opLen) === 0) {
                $before = $i === 0 ? ' ' : $stream[$i - 1];
                $afterPos = $i + $opLen;
                $after = $afterPos >= $len ? ' ' : $stream[$afterPos];
                if (preg_match('/\s/', $before) && ($afterPos === $len || preg_match('/\s/', $after))) {
                    return $i;
                }
            }

            $i++;
        }
        return -1;
    }

    /**
     * Like {@see findNextOperator} but matches the EARLIEST occurrence of any
     * operator in $ops with a single forward scan, returning [offset, op].
     *
     * Used by parseContentStreamModel's inner loop to avoid scanning the
     * stream once per operator type at every step (which is O(n^2) per
     * stream). Shares the exact string-state-aware tokenizer so phantom
     * operators inside `(...)` / `<...>` / comments are ignored. Returns
     * [-1, null] when no operator in $ops is found (or on unterminated hex).
     *
     * @param string   $stream Decoded content stream.
     * @param int      $start  Byte offset to scan from.
     * @param string[] $ops    Operator tokens to look for (e.g. ['Tj','TJ','Tf','Tm']).
     *
     * @return array{0:int,1:?string} [offset, matched-op] or [-1, null].
     */
    private function findNextAnyOperator($stream, $start, array $ops) {
        $len = strlen($stream);
        $i = $start;

        while ($i < $len) {
            $c = $stream[$i];

            // Skip PDF comments (`%` to end-of-line).
            if ($c === '%') {
                $i++;
                while ($i < $len && $stream[$i] !== "\n" && $stream[$i] !== "\r") {
                    $i++;
                }
                continue;
            }

            // Skip literal strings `(...)` with backslash escapes + nesting.
            if ($c === '(') {
                $depth = 1;
                $i++;
                while ($i < $len && $depth > 0) {
                    $cc = $stream[$i];
                    if ($cc === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($cc === '(') {
                        $depth++;
                    } elseif ($cc === ')') {
                        $depth--;
                    }
                    $i++;
                }
                continue;
            }

            // Skip hex strings `<...>` (but not `<<` dict delimiters).
            if ($c === '<' && $i + 1 < $len && $stream[$i + 1] !== '<') {
                $closing = strpos($stream, '>', $i + 1);
                if ($closing === false) {
                    return [-1, null];
                }
                $i = $closing + 1;
                continue;
            }

            // Token start must follow whitespace (or BOF). At most one of the
            // distinct two-char ops can match at a given position.
            $before = $i === 0 ? ' ' : $stream[$i - 1];
            if (preg_match('/\s/', $before)) {
                foreach ($ops as $op) {
                    $opLen = strlen($op);
                    if ($i + $opLen <= $len && substr_compare($stream, $op, $i, $opLen) === 0) {
                        $afterPos = $i + $opLen;
                        $after = $afterPos >= $len ? ' ' : $stream[$afterPos];
                        if ($afterPos === $len || preg_match('/\s/', $after)) {
                            return [$i, $op];
                        }
                    }
                }
            }

            $i++;
        }

        return [-1, null];
    }

    /**
     * Find the operand of a Tj operator at $tjPos.
     *
     * Returns [operandStart, operandEnd, operandBytes, shape]:
     *   - operandStart: byte offset of `(` or `<` opening
     *   - operandEnd: byte offset of `)` or `>` closing PLUS ONE
     *   - operandBytes: raw decoded operand bytes
     *   - shape: 'literal' or 'hex'
     *
     * Returns null if the operand can't be parsed.
     *
     * @param string $stream Decoded content stream.
     * @param int    $tjPos  Byte offset of the `Tj` operator.
     *
     * @return array{0:int,1:int,2:string,3:string}|null
     */
    private function findTjOperand($stream, $tjPos) {
        // Scan backward from $tjPos for the closing `)` or `>`.
        $closingPos = -1;
        $closingChar = '';
        for ($i = $tjPos - 1; $i >= 0; $i--) {
            $c = $stream[$i];
            if (preg_match('/\s/', $c)) continue;
            if ($c === ')' || $c === '>') {
                $closingPos = $i;
                $closingChar = $c;
            }
            break;
        }
        if ($closingPos < 0) return null;

        $shape = $closingChar === ')' ? 'literal' : 'hex';
        $openChar = $shape === 'literal' ? '(' : '<';
        $closeChar = $closingChar;

        // Scan backward from $closingPos to find the matching opener.
        // Literal strings can have nested () with escaping; we handle
        // depth correctly for the common case. Hex strings can't nest.
        if ($shape === 'hex') {
            $openPos = strrpos(substr($stream, 0, $closingPos), '<');
            if ($openPos === false) return null;
            $hex = substr($stream, $openPos + 1, $closingPos - $openPos - 1);
            $hex = preg_replace('/\s+/', '', $hex);
            // Explicit `=== false` check rather than `?:` because PHP's
            // truthiness rules treat a single ASCII "0" byte (encoded as
            // `<30>`) as falsy and would silently replace it with `''`.
            $bytes = @hex2bin($hex);
            if ($bytes === false) {
                $bytes = '';
            }
            return [$openPos, $closingPos + 1, $bytes, 'hex'];
        } else {
            $depth = 1;
            $openPos = -1;
            for ($i = $closingPos - 1; $i >= 0; $i--) {
                $c = $stream[$i];
                // Backslash-escape parity: count consecutive `\` bytes
                // immediately preceding $i. A paren is escaped iff that
                // count is ODD (each unescaped pair `\\` is a literal
                // backslash, so `\\)` ends with an UNESCAPED `)` after
                // 2 backslashes — they cancel). Previous code blindly
                // skipped any paren preceded by `\` and mis-parsed
                // `(C:\\Users\\foo)` etc.
                if ($c === ')' || $c === '(') {
                    $bsCount = 0;
                    $j = $i - 1;
                    while ($j >= 0 && $stream[$j] === '\\') {
                        $bsCount++;
                        $j--;
                    }
                    if (($bsCount & 1) === 1) {
                        // Odd → escaped paren → skip.
                        continue;
                    }
                }
                if ($c === ')') $depth++;
                elseif ($c === '(') {
                    $depth--;
                    if ($depth === 0) { $openPos = $i; break; }
                }
            }
            if ($openPos < 0) return null;
            $literal = substr($stream, $openPos + 1, $closingPos - $openPos - 1);
            $bytes = $this->unescapePdfLiteral($literal);
            return [$openPos, $closingPos + 1, $bytes, 'literal'];
        }
    }

    /**
     * Resolve operand bytes to a Unicode string via the active font.
     *
     * @param string                                                                                  $bytes Operand bytes (raw).
     * @param string                                                                                  $shape 'literal' or 'hex'.
     * @param array{encoding: \ddn\sapp\FontEncoding, cmap: ?\ddn\sapp\CMap, baseFont: string}|null $info Font info or null.
     *
     * @return string Unicode (UTF-8); empty when the font can't resolve.
     */
    private function resolveOperandToUnicode($bytes, $shape, $info) {
        // No font info: treat bytes as Latin-1 / ASCII passthrough (mirrors
        // the byte-level PoC behaviour). This is the path that keeps the
        // existing PoC verify gate green.
        if ($info === null) {
            return $bytes;
        }

        $encoding = $info['encoding'];
        $cmap = $info['cmap'];

        // Identity-H / -V: requires a CMap. Iterate 2 bytes at a time.
        if ($encoding->isIdentityH() || $encoding->isIdentityV()) {
            if ($cmap === null) return '';
            $out = '';
            $len = strlen($bytes);
            $width = max(1, $cmap->cidWidth() ?: 2);
            for ($i = 0; $i + $width <= $len; $i += $width) {
                $cid = substr($bytes, $i, $width);
                $u = $cmap->cidToUnicode($cid);
                if ($u === '') {
                    // Unknown CID — substitute U+FFFD (REPLACEMENT
                    // CHARACTER) rather than throwing away the entire
                    // resolved prefix. Common in subset fonts where the
                    // ToUnicode CMap only covers glyphs actually used
                    // earlier in the document; needle matching on a
                    // string with one or two replacement chars is
                    // strictly better than empty resolution.
                    $u = "\xEF\xBF\xBD";
                }
                $out .= $u;
            }
            return $out;
        }

        // Simple font: byte-at-a-time via FontEncoding, with CMap as override.
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $cid = $bytes[$i];
            if ($cmap !== null) {
                $u = $cmap->cidToUnicode($cid);
                if ($u !== '') { $out .= $u; continue; }
            }
            $u = $encoding->byteToUnicode(ord($cid));
            if ($u === '') {
                // Fallback: treat as Latin-1 passthrough.
                $u = $cid;
            }
            $out .= $u;
        }
        return $out;
    }

    /**
     * Encode a Unicode placeholder string back to operand bytes via
     * the active font's forward map.
     *
     * @param string                                                                                  $unicode  UTF-8 placeholder.
     * @param array{encoding: \ddn\sapp\FontEncoding, cmap: ?\ddn\sapp\CMap, baseFont: string}|null $info     Font info or null.
     *
     * @return string|null Operand bytes; null when the font can't encode every character.
     */
    private function encodeUnicodeViaFont($unicode, $info) {
        // No font info: emit the bytes verbatim (Latin-1 / ASCII).
        if ($info === null) return $unicode;

        $encoding = $info['encoding'];
        $cmap = $info['cmap'];

        $isIdentity = $encoding->isIdentityH() || $encoding->isIdentityV();
        $out = '';
        // Walk Unicode codepoint by codepoint.
        $chars = preg_split('//u', $unicode, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) return null;

        foreach ($chars as $ch) {
            if ($isIdentity) {
                if ($cmap === null) return null;
                $cid = $cmap->unicodeToCid($ch);
                if ($cid === null) return null;
                $out .= $cid;
            } else {
                // Try CMap forward map first (preferred when present —
                // it represents what the document's font actually has).
                if ($cmap !== null) {
                    $cid = $cmap->unicodeToCid($ch);
                    if ($cid !== null) { $out .= $cid; continue; }
                }
                $byte = $encoding->unicodeToByte($ch);
                if ($byte === null) return null;
                $out .= chr($byte);
            }
        }
        return $out;
    }

    /**
     * Splice a placeholder into operand bytes at the location of $needle's
     * match (in text space). Returns the new operand bytes; on a CID-split
     * mismatch, records the diagnostic and returns the original bytes.
     *
     * @param string                                                                                  $operandBytes Current operand bytes.
     * @param string                                                                                  $shape        'literal' or 'hex'.
     * @param string                                                                                  $needle       Unicode needle.
     * @param string                                                                                  $encodedPlaceholder Operand bytes for the placeholder.
     * @param array{encoding: \ddn\sapp\FontEncoding, cmap: ?\ddn\sapp\CMap, baseFont: string}|null $info         Font info.
     * @param array                                                                                   $stats        Diagnostic accumulator (by-ref).
     * @param int                                                                                     $oid          Object id.
     *
     * @return string New operand bytes.
     */
    private function spliceOperand($operandBytes, $shape, $needle, $encodedPlaceholder, $info, array &$stats, $oid) {
        // Walk the operand byte-by-byte (or CID-by-CID for Identity-H)
        // building (text_offset → byte_offset) and (byte_offset → text_offset)
        // indices. Then locate the needle in the resolved text and
        // splice at the byte offsets.
        $isIdentity = ($info !== null) &&
            ($info['encoding']->isIdentityH() || $info['encoding']->isIdentityV());
        $cmap = $info['cmap'] ?? null;
        $encoding = $info['encoding'] ?? null;
        $width = $isIdentity ? max(1, $cmap !== null ? $cmap->cidWidth() : 2) : 1;

        $textBuffer = '';
        $byteOffsets = [];  // byteOffsets[i] = start byte offset of the i-th CID

        $len = strlen($operandBytes);
        for ($i = 0; $i + $width <= $len; $i += $width) {
            $cid = substr($operandBytes, $i, $width);
            $byteOffsets[] = $i;
            $u = '';
            if ($cmap !== null) {
                $u = $cmap->cidToUnicode($cid);
            }
            if ($u === '' && $encoding !== null && !$isIdentity) {
                $u = $encoding->byteToUnicode(ord($cid));
            }
            if ($u === '' && !$isIdentity) {
                // Latin-1 fallback for simple fonts.
                $u = $cid;
            }
            $textBuffer .= $u;
        }
        $byteOffsets[] = $len;  // sentinel

        // Locate needle in textBuffer.
        $matchPos = strpos($textBuffer, $needle);
        if ($matchPos === false) return $operandBytes;

        // Translate text offset to byte offset. CID-split: needle's start
        // or end may fall in the INTERIOR of a CID's resolved text (when
        // CMap maps a single CID to a multi-codepoint sequence). In that
        // case, skip + diagnose.
        $matchEnd = $matchPos + strlen($needle);

        $accum = 0;
        $startByte = -1; $endByte = -1;
        for ($i = 0; $i < count($byteOffsets) - 1; $i++) {
            $cidBytes = substr($operandBytes, $byteOffsets[$i], $width);
            $u = '';
            if ($cmap !== null) $u = $cmap->cidToUnicode($cidBytes);
            if ($u === '' && $encoding !== null && !$isIdentity) {
                $u = $encoding->byteToUnicode(ord($cidBytes));
            }
            if ($u === '' && !$isIdentity) $u = $cidBytes;

            $cidStart = $accum;
            $cidEnd = $accum + strlen($u);

            if ($cidStart === $matchPos) {
                $startByte = $byteOffsets[$i];
            } elseif ($cidStart < $matchPos && $cidEnd > $matchPos) {
                // Needle starts inside this CID's interior — CID split.
                $stats['cid_split_mismatch'][$oid][$needle] = $byteOffsets[$i];
                return $operandBytes;
            }
            if ($cidEnd === $matchEnd) {
                $endByte = $byteOffsets[$i + 1];
                break;
            } elseif ($cidStart < $matchEnd && $cidEnd > $matchEnd) {
                $stats['cid_split_mismatch'][$oid][$needle] = $byteOffsets[$i];
                return $operandBytes;
            }
            $accum = $cidEnd;
        }

        if ($startByte < 0 || $endByte < 0) return $operandBytes;

        // Splice.
        return substr($operandBytes, 0, $startByte)
             . $encodedPlaceholder
             . substr($operandBytes, $endByte);
    }

    /**
     * Apply PDF literal-string escape sequences to a raw bytestring.
     *
     * Reverse of `unescapePdfLiteral`. Escapes `\\`, `(`, `)`, plus
     * non-printable bytes via octal sequences.
     *
     * @param string $bytes Raw bytes.
     *
     * @return string Escaped literal (without surrounding parens).
     */
    private function escapePdfLiteral($bytes) {
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $c = $bytes[$i];
            $o = ord($c);
            if ($c === '\\' || $c === '(' || $c === ')') {
                $out .= '\\' . $c;
            } elseif ($o < 0x20 || $o >= 0x80) {
                // PDF 1.7 §7.3.4.2 Table 3: only bytes outside the
                // printable ASCII range need escaping. 0x7F (DEL) is
                // technically a control char but spec-allowed
                // unescaped — no need to spend 4 bytes per DEL.
                $out .= '\\' . str_pad(decoct($o), 3, '0', STR_PAD_LEFT);
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /**
     * Decode PDF literal-string escape sequences.
     *
     * Handles: `\n \r \t \b \f \\ \( \) \ooo` (octal, 1-3 digits).
     *
     * @param string $literal Escaped literal (without surrounding parens).
     *
     * @return string Raw bytes.
     */
    private function unescapePdfLiteral($literal) {
        $out = '';
        $len = strlen($literal);
        $i = 0;
        while ($i < $len) {
            $c = $literal[$i];
            if ($c !== '\\') { $out .= $c; $i++; continue; }
            if ($i + 1 >= $len) break;
            $next = $literal[$i + 1];
            switch ($next) {
                case 'n': $out .= "\n"; $i += 2; break;
                case 'r': $out .= "\r"; $i += 2; break;
                case 't': $out .= "\t"; $i += 2; break;
                case 'b': $out .= "\x08"; $i += 2; break;
                case 'f': $out .= "\f"; $i += 2; break;
                case '\\': case '(': case ')': $out .= $next; $i += 2; break;
                default:
                    // Octal escape.
                    if (ctype_digit($next)) {
                        $oct = '';
                        $j = $i + 1;
                        while ($j < $len && ctype_digit($literal[$j]) && strlen($oct) < 3) {
                            $oct .= $literal[$j]; $j++;
                        }
                        $out .= chr(intval($oct, 8));
                        $i = $j;
                    } else {
                        // Unknown escape: skip the backslash.
                        $out .= $next; $i += 2;
                    }
                    break;
            }
        }
        return $out;
    }

    /**
     * This functions outputs the document to a buffer object, ready to be dumped to a file.
     * @param rebuild whether we are rebuilding the whole xref table or not (in case of incremental versions, we should use "false")
     * @return ?Buffer a buffer that contains a pdf dumpable document or null if a signature was to be created but could not be
     *         created (e.g. missing certificate)
     */
    public function to_pdf_file_b($rebuild = false) : ?Buffer {
        // We made no updates, so return the original doc
        if (($rebuild === false) && (count($this->_pdf_objects) === 0) && ($this->_certificate === null) && ($this->_appearance === null))
            return new Buffer($this->_buffer);

        // Save the state prior to generating the objects
        $this->push_state();

        // Update the timestamp
        $this->update_mod_date();

        $_signature = null;
        if (($this->_appearance !== null) || ($this->_certificate !== null)) {
            $_signature = $this->_generate_signature_in_document();
            if ($_signature === false) {
                $this->pop_state();
                return p_error("could not generate the signed document", null);
            }
        }

        // Generate the first part of the document
        [ $_doc_to_xref, $_obj_offsets ] = $this->_generate_content_to_xref($rebuild);
        $xref_offset = $_doc_to_xref->size();

        if ($_signature !== null) {
            $_obj_offsets[$_signature->get_oid()] = $_doc_to_xref->size();
            $xref_offset +=  strlen($_signature->to_pdf_entry());
        }

        $doc_version_string = str_replace("PDF-", "", $this->_pdf_version_string);

        // The version considered for the cross reference table depends on the version of the current xref table,
        //   as it is not possible to mix xref tables. Anyway we are
        $target_version = $this->_xref_table_version;
        if ($this->_xref_table_version >= "1.5") {
            // i.e. xref streams
            if ($doc_version_string > $target_version)
                $target_version = $doc_version_string;
        } else {
            // i.e. xref+trailer
            if ($doc_version_string < $target_version)
                $target_version = $doc_version_string;
        }

        if ($target_version >= "1.5") {
            p_debug("generating xref using cross-reference streams");

            // Create a new object for the trailer
            $trailer = $this->create_object(
                clone $this->_pdf_trailer_object
            );

            // Add this object to the offset table, to be also considered in the xref table
            $_obj_offsets[$trailer->get_oid()] = $xref_offset;

            // Generate the xref cross-reference stream
            $xref = PDFUtilFnc::build_xref_1_5($_obj_offsets);

            // Set the parameters for the trailer
            $trailer["Index"] = explode(" ", $xref["Index"]);
            $trailer["W"] = $xref["W"];
            $trailer["Size"] = $this->_max_oid + 1;
            $trailer["Type"] = "/XRef";

            // Not needed to generate new IDs, as in metadata the IDs will be set
            // $ID1 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $xref["stream"]);
            $ID2 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $this->_pdf_trailer_object);
            // $trailer["ID"] = [ new PDFValueHexString($ID1), new PDFValueHexString($ID2) ];
            $trailer["ID"] = [ $trailer["ID"][0], new PDFValueHexString(strtoupper($ID2)) ];

            // We are not using predictors nor encoding
            if (isset($trailer["DecodeParms"])) unset($trailer["DecodeParms"]);

            // We are not compressing the stream
            if (isset($trailer["Filter"])) unset($trailer["Filter"]);
            $trailer->set_stream($xref["stream"], false);

            // If creating an incremental modification, point to the previous xref table
            if ($rebuild === false)
                $trailer['Prev'] = $this->_xref_position;
            else
                // If rebuilding the document, remove the references to previous xref tables, because it will be only one
                if (isset($trailer['Prev']))
                    unset($trailer['Prev']);

            // And generate the part of the document related to the xref
            $_doc_from_xref = new Buffer($trailer->to_pdf_entry());
            $_doc_from_xref->data("startxref" . __EOL . "$xref_offset" . __EOL ."%%EOF" . __EOL);
        } else {
            p_debug("generating xref using classic xref...trailer");
            $xref_content = PDFUtilFnc::build_xref($_obj_offsets);

            // Update the trailer
            $this->_pdf_trailer_object['Size'] = $this->_max_oid + 1;

            if ($rebuild === false) {
                $this->_pdf_trailer_object['Prev'] = $this->_xref_position;
            } else {
                // Rebuilding: drop pointers to previous xref tables. The
                // rebuilt output is a single-revision file by construction,
                // so carrying /Prev (or /XRefStm from a hybrid input) over
                // from the source trailer leaves the new file pointing
                // into a revision chain that no longer exists in the new
                // buffer. Any later PDFDoc::from_string() on our own
                // output then trips ValueError at PDFUtilFnc:266 when
                // get_xref_1_4 recurses down /Prev past EOF. Mirrors the
                // equivalent unset already present in the 1.5 xref-stream
                // branch above.
                if (isset($this->_pdf_trailer_object['Prev'])) {
                    unset($this->_pdf_trailer_object['Prev']);
                }
                if (isset($this->_pdf_trailer_object['XRefStm'])) {
                    unset($this->_pdf_trailer_object['XRefStm']);
                }
            }

            // Not needed to generate new IDs, as in metadata the IDs may be set
            // $ID1 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $xref_content);
            // $ID2 = md5("" . (new \DateTime())->getTimestamp() . "-" . $this->_xref_position . $this->_pdf_trailer_object);
            // $this->_pdf_trailer_object['ID'] = new PDFValueList(
            //    [ new PDFValueHexString($ID1), new PDFValueHexString($ID2) ]
            // );

            // Generate the part of the document related to the xref
            $_doc_from_xref = new Buffer($xref_content);
            $_doc_from_xref->data("trailer\n$this->_pdf_trailer_object");
            $_doc_from_xref->data("\nstartxref\n$xref_offset\n%%EOF\n");
        }

        if ($_signature !== null) {
            // In case that the document is signed, calculate the signature

            $_signature->set_sizes($_doc_to_xref->size(), $_doc_from_xref->size());
            $_signature["Contents"] = new PDFValueSimple("");
            $_signable_document = new Buffer($_doc_to_xref->get_raw() . $_signature->to_pdf_entry() . $_doc_from_xref->get_raw());
            $certificate = $_signature->get_certificate();
            $extracerts = (array_key_exists('extracerts', $certificate)) ? $certificate['extracerts'] : null;
            $cms = new CMS;
            $cms->signature_data['hashAlgorithm'] = 'sha256';
            $cms->signature_data['privkey'] = $certificate['pkey'];
            $cms->signature_data['extracerts'] = $extracerts;
            $cms->signature_data['signcert'] =  $certificate['cert'];
            $cms->signature_data['ltv'] = $_signature->get_ltv();
            $cms->signature_data['tsa'] = $_signature->get_tsa();
            $signature_contents = $cms->pkcs7_sign($_signable_document->get_raw());
            $signature_contents = str_pad($signature_contents, PDFSignatureObject::$__SIGNATURE_MAX_LENGTH, '0');

            // Then restore the contents field
            $_signature["Contents"] = new PDFValueHexString($signature_contents);

            // Add this object to the content previous to this document xref
            $_doc_to_xref->data($_signature->to_pdf_entry());
        }

        // Reset the state to make signature objects not to mess with the user's objects
        $this->pop_state();
        return new Buffer($_doc_to_xref->get_raw() . $_doc_from_xref->get_raw());
    }

    /**
     * This functions outputs the document to a string, ready to be written
     * @return string|false a buffer that contains a pdf document or false if a signature was to be created but could not be 
     */
    public function to_pdf_file_s($rebuild = false) {
        $pdf_content = $this->to_pdf_file_b($rebuild);
        if ($pdf_content === null) {
            return false;
        }
        return $pdf_content->get_raw();
    }

    /**
     * This function writes the document to a file
     * @param filename the name of the file to be written (it will be overwritten, if exists)
     * @return written true if the file has been correcly written to the file; false otherwise
     */
    public function to_pdf_file($filename, $rebuild = false) {
        $pdf_content = $this->to_pdf_file_b($rebuild);
        if ($pdf_content === null) {
            return false;
        }

        $file = fopen($filename, "wb");
        if ($file === false) {
            return p_error("failed to create the file");
        }
        if (fwrite($file, $pdf_content->get_raw()) !== $pdf_content->size()) {
            fclose($file);
            return p_error("failed to write to file");
        }
        fclose($file);
        return true;
    }

    /**
     * Gets the page object which is rendered in position i
     * @param i the number of page (according to the rendering order)
     * @return page the page object
     */
    public function get_page($i) {
        if ($i < 0) return false;
        if ($i >= count($this->_pages_info)) return false;
        return $this->get_object($this->_pages_info[$i]['id']);
    }

    /**
     * Gets the size of the page in the form of a rectangle [ x0 y0 x1 y1 ]
     * @param i the number of page (according to the rendering order), or the page object
     * @return box the bounding box of the page
     */
    public function get_page_size($i) {
        $pageinfo = false;

        if (is_int($i)) {
            if ($i < 0) return false;
            if ($i > count($this->_pages_info)) return false;

            $pageinfo = $this->_pages_info[$i]['info'];
        } else {
            foreach ($this->_pages_info as $k => $info) {
                if ($info['oid'] === $i->get_oid()) {
                    $pageinfo = $info['info'];
                    break;
                }
            }
        }

        // The page has not been found
        if (($pageinfo === false) || (!isset($pageinfo['size'])))
            return false;

        return $pageinfo['size'];
    }

    /**
     * This function builds the page IDs for object with id oid. If it is a page, it returns the oid; if it is not and it has
     *   kids and every kid is a page (or a set of pages), it finds the pages.
     * @param oid the object id to inspect
     * @return pages the ordered list of page ids corresponding to object oid, or false if any of the kid objects
     *               is not of type page or pages.
     */
    protected function _get_page_info($oid, $info = []) {
        $object = $this->get_object($oid);
        if ($object === false)
            return p_error("could not get information about the page");

        $page_ids = [];

        if ($object["Type"] === false) {
            return p_error("object $oid has no type, so cannot be a page or pages");
        }

        switch ($object["Type"]->val()) {
            case "Pages":
                $kids = $object["Kids"];
                $kids = $kids->get_object_referenced();
                if ($kids !== false) {
                    if (isset($object['MediaBox'])) {
                        $info['size'] = $object['MediaBox']->val();
                    }
                    foreach ($kids as $kid) {
                        $ids = $this->_get_page_info($kid, $info);
                        if ($ids === false)
                            return false;
                        array_push($page_ids, ...$ids);
                    }
                } else {
                    return p_error("could not get the pages");
                }
                break;
            case "Page":
                if (isset($object['MediaBox']))
                    $info['size'] = $object['MediaBox']->val();
                return [ [ 'id' => $oid, 'info' => $info ]  ];
            default:
                return false;
        }
        return $page_ids;
    }

    /**
     * Obtains an ordered list of objects that contain the ids of the page objects of the document.
     *   The order is made according to the catalog and the document structure.
     * @return list an ordered list of the id of the page objects, or false if could not be found
     */
    protected function _acquire_pages_info() {
        $root = $this->_pdf_trailer_object["Root"];
        if (($root === false) || (($root = $root->get_object_referenced()) === false))
            return p_error("could not find the root object from the trailer");

        $root = $this->get_object($root);
        if ($root !== false) {
            $pages = $root["Pages"];
            if (($pages === false) || (($pages = $pages->get_object_referenced()) === false))
                return p_error("could not find the pages for the document");

            $this->_pages_info = $this->_get_page_info($pages);
        } else
            p_warning("root object does not exist, so cannot get information about pages");
    }


    /**
     * This function compares this document with other document, object by object. The idea is to compare the objects with the same oid in the
     *  different documents, checking field by field; it does not take into account the streams.
     */
    public function compare($other) {
        $other_objects = [];
        foreach ($other->get_object_iterator(false) as $oid => $object) {
            $other_objects[$oid] = $object;
        }

        $differences = [];

        foreach ($this->get_object_iterator(false) as $oid => $object) {
            if (isset($other_objects[$oid])) {
                // The object exists, so we need to compare
                $diff = $object->get_value()->diff($other_objects[$oid]->get_value());
                if ($diff !== null) {
                    $differences[$oid] = new PDFObject($oid, $diff);
                }
            } else {
                $differences[$oid] = new PDFObject($oid, $object->get_value());
            }

        }
        return $differences;
    }

    /**
     * Obtains the tree of objects in the PDF Document. The result is an array of DependencyTreeObject objects (indexed by the oid), where
     *  each element has a set of children that can be retrieved using the iterator (foreach $o->children() as $oid => $object ...)
     */
    public function get_object_tree() {

        // Prepare the return value
        $objects = [];

        foreach ($this->_xref_table as $oid => $offset) {
            if ($offset === null) continue;

            $o = $this->get_object($oid);
            if ($o === false) continue;

        // foreach ($this->get_object_iterator() as $oid => $o) {

            // Create the object in the dependency tree and add it to the list of objects
            if (! array_key_exists($oid, $objects)) {
                $objects[$oid] = new DependencyTreeObject($oid, $o["Type"]);
            }

            // The object is a PDFObject so we need the PDFValueObject to get the value of the fields
            $object = $objects[$oid];
            $val = $o->get_value();

            // We'll only consider those objects that may create an structure (i.e. the objects, whose fields may include references to other objects)
            if (is_a($val, "ddn\\sapp\\pdfvalue\\PDFValueObject")) {
                $references = references_in_object($val, $oid);
            } else {
                $references = $val->get_object_referenced();
                if ($references === false)
                    continue;
                if (!is_array($references)) $references = [ $references ];
            }

            // p_debug("$oid references " . implode(", ", $references));
            foreach ($references as $r_object) {
                if (! array_key_exists($r_object, $objects)) {
                    $r_object_o = $this->get_object($r_object);
                    $objects[$r_object] = new DependencyTreeObject($r_object, $r_object_o["Type"]);
                }
                $object->addchild($r_object, $objects[$r_object]);
            }
        }

        //
        $xref_children = [];
        foreach ($objects as $oid => $t_object) {
            if ($t_object->info == "/XRef") {
                array_push($xref_children, ...iterator_to_array($t_object->children()));
            }
        }

        $xref_children = array_unique($xref_children);

        // Remove those objects that are child of other objects from the top of the tree
        foreach ($objects as $oid => $t_object) {
            if (($t_object->is_child > 0) || (in_array($t_object->info, [ "/XRef", "/ObjStm"] ))) {
                if (! in_array($oid, $xref_children))
                    unset($objects[$oid]);
            }
        }

        return $objects;
    }


    /**
     * Retrieve the signatures in the document
     * @return array of signatures in the original document
     */
    public function get_signatures() {

        // Prepare the return value
        $signatures = [];

        foreach ($this->_xref_table as $oid => $offset) {
            if ($offset === null) continue;

            $o = $this->get_object($oid);
            if ($o === false) continue;

            $o_value = $o->get_value()->val();
            if (! is_array($o_value) || ! isset($o_value['Type'])) continue;
            if ($o_value['Type']->val() != 'Sig') continue;

            $signature = ['content' => $o_value['Contents']->val()];

            try {
                $cert=[];

                openssl_pkcs7_read(
                    "-----BEGIN CERTIFICATE-----\n"
                       . chunk_split(base64_encode(hex2bin($signature['content'])), 64, "\n")
                       . "-----END CERTIFICATE-----\n",
                   $cert
                );

                $signature += openssl_x509_parse($cert[0] ?? '') ?: [];
            } catch (\Throwable $e) {}

            $signatures[] = $signature;
        }

        return $signatures;
    }

    /**
     * Retrieve the number of signatures in the document
     * @return int signatures number in the original document
     */
    public function get_signature_count() {
        return count($this->get_signatures());
    }


    /**
     * Generates a new document that is the result of signing the current
     * document
     * @param certfile a file that contains a user certificate in pkcs12 format, or an array [ 'cert' => <cert.pem>, 'pkey' => <key.pem> ]
     *                 that would be the output of openssl_pkcs12_read
     * @param password the password to read the private key
     * @param page_to_appear the page (zero based) in which the signature will appear
     * @param imagefilename an image file name (or an image in a buffer, with symbol '@' prepended) that will be put inside the rect; if
     *                      set to null, the signature will be invisible.
     * @param px
     * @param py x and y position for the signature.
     * @param size
     *          - if float, it will be a scale for the size of the image to be included as a signature appearance
     *          - if array [ width, height ], it will be the width and the height for the image to be included as a signature appearance (if
     *            one of these values is null, it will fallback to the actual width or height of the image)
     */
    public function sign_document($certfile, $password = null, $page_to_appear = 0, $imagefilename = null, $px = 0, $py = 0, $size = null) {

        if ($imagefilename !== null) {
            $position = [ ];
            $imagesize = @getimagesize($imagefilename);
            if ($imagesize === false) {
                return p_warning("failed to open the image $image");
            }
            if (($page_to_appear < 0) || ($page_to_appear > $this->get_page_count() - 1)) {
                return p_error("invalid page number");
            }
            $pagesize = $this->get_page_size($page_to_appear);
            if ($pagesize === false) {
                return p_error("failed to get page size");
            }

            $pagesize = explode(" ", $pagesize[0]->val());

            // Get the bounding box for the image
            $p_x = intval("". $pagesize[0]);
            $p_y = intval("". $pagesize[1]);
            $p_w = intval("". $pagesize[2]) - $p_x;
            $p_h = intval("". $pagesize[3]) - $p_y;

            // Add the position for the image
            $p_x = $p_x + $px;
            $p_y = $p_y + $py;

            $i_w = $imagesize[0];
            $i_h = $imagesize[1];

            if (is_array($size)) {
                if (count($size) != 2) {
                    return p_error("invalid size");
                }
                $width = $size[0];
                $height = $size[1];
            } else if ($size === null) {
                $width = $i_w;
                $height = $i_h;
            } else if (is_float($size) || is_int($size)) {
                $width = $i_w * $size;
                $height = $i_h * $size;
            } else {
                return p_error("invalid size format");
            }

            $i_w = $width===null?$imagesize[0]:$width;
            $i_h = $height===null?$imagesize[1]:$height;

            // Set the image appearance and the certificate file
            $this->set_signature_appearance($page_to_appear, [ $p_x, $p_y, $p_x + $i_w, $p_y + $i_h ], $imagefilename);
        }

        if (!$this->set_signature_certificate($certfile, $password)) {
            return p_error("the certificate or the signature is not valid");
        }

        $docsigned = $this->to_pdf_file_s();
        if ($docsigned === false) {
            return p_error("failed to sign the document");
        }
        return PDFDoc::from_string($docsigned);
    }
}
