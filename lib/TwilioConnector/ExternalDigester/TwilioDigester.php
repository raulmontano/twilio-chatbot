<?php

namespace Inbenta\TwilioConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\TwilioConnector\Helpers\Helper;

class TwilioDigester extends DigesterInterface
{
    protected $conf;
    protected $channel;
    protected $langManager;
    protected $session;
    protected $isWhatsappRequest = false;
    protected $attachableFormats = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'mp4', 'avi', 'mp3'];

    /**
     * Digester contructor
     */
    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'twilio';
        $this->conf = $conf;
        $this->session = $session;
    }

    /**
     *	Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     **	Checks if a request belongs to the digester channel
     **/
    public static function checkRequest($_request)
    {
        parse_str($_request, $request);

        if (isset($request['Body'])) {
            return true;
        }

        return false;
    }

    /**
     * Validate if the request comes from Whatsapp
     * @param array $request
     */
    private function requestFromWhatsapp($request)
    {
        if (isset($request['From'])) {
            if (strpos($request['From'], "whatsapp") !== false) {
                $this->isWhatsappRequest = true;
            }
        }
    }

    /**
     * Formats a channel request into an Inbenta Chatbot API request
     * @param string $_request
     * @return array
     */
    public function digestToApi($_request)
    {
        parse_str($_request, $request);

        $this->requestFromWhatsapp($request);

        $output = [];
        if ($this->session->has('options')) {

            $lastUserQuestion = $this->session->get('lastUserQuestion');
            $options = $this->session->get('options');

            $this->session->delete('options');
            $this->session->delete('lastUserQuestion');
            $this->session->delete('hasRelatedContent');

            if (isset($request['Body'])) {

                $userMessage = $request['Body'];

                $selectedOption = false;
                $selectedOptionText = "";
                $selectedEscalation = "";
                $isRelatedContent = false;
                $isListValues = false;
                $isPolar = false;
                $isEscalation = false;
                $optionSelected = false;
                foreach ($options as $option) {
                    if (isset($option->list_values)) {
                        $isListValues = true;
                    } else if (isset($option->related_content)) {
                        $isRelatedContent = true;
                    } else if (isset($option->is_polar)) {
                        $isPolar = true;
                    } else if (isset($option->escalate)) {
                        $isEscalation = true;
                    }

                    if (
                        $userMessage == $option->opt_key ||
                        Helper::removeAccentsToLower($userMessage) === Helper::removeAccentsToLower($this->langManager->translate($option->label))
                    ) {
                        if ($isListValues || $isRelatedContent || (isset($option->attributes) && isset($option->attributes->DYNAMIC_REDIRECT) && $option->attributes->DYNAMIC_REDIRECT == 'escalationStart')) {
                            $selectedOptionText = $option->label;
                        } else if ($isEscalation) {
                            $selectedEscalation = $option->escalate;
                        } else {
                            $selectedOption = $option;
                            $lastUserQuestion = isset($option->title) && !$isPolar ? $option->title : $lastUserQuestion;
                        }
                        $optionSelected = true;
                        break;
                    }
                }

                if (!$optionSelected) {
                    if ($isListValues) { //Set again options for variable
                        if ($this->session->get('optionListValues', 0) < 1) { //Make sure only enters here just once
                            $this->session->set('options', $options);
                            $this->session->set('lastUserQuestion', $lastUserQuestion);
                            $this->session->set('optionListValues', 1);
                        } else {
                            $this->session->delete('options');
                            $this->session->delete('lastUserQuestion');
                            $this->session->delete('optionListValues');
                        }
                    } else if ($isPolar) { //For polar, on wrong answer, goes for NO
                        $request['Body'] = "No";
                    }
                }

                if ($selectedOption) {
                    $output[] = ['option' => $selectedOption->value];
                } else if ($selectedOptionText !== "") {
                    $output[] = ['message' => $selectedOptionText];
                } else if ($isEscalation && $selectedEscalation !== "") {
                    if ($selectedEscalation === false) {
                        $output[] = ['message' => "no"];
                    } else {
                        $output[] = ['escalateOption' => $selectedEscalation];
                    }
                } else {
                    $output[] = ['message' => $request['Body']];
                }
            }
        } else if (isset($request['Body'])) {
            $output[0] = ['message' => $request['Body']];

            if (isset($request['MediaUrl0'])) {
                $output[0] = $this->mediaFileToHyperchat($request);
            }
        }
        return $output;
    }

    /**
     * Formats an Inbenta Chatbot API response into a channel request
     * @param object $request
     * @param string $lastUserQuestion = ''
     */
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
        //Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif (!is_null($this->checkApiMessageType($request))) {
            $messages = array('answers' => $request);
        }

        $output = [];
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digestedMessage = [];
            switch ($msgType) {
                case 'answer':
                    $digestedMessage = $this->digestFromApiAnswer($msg, $lastUserQuestion);
                    break;
                case 'polarQuestion':
                    $digestedMessage = $this->digestFromApiPolarQuestion($msg, $lastUserQuestion);
                    break;
                case 'multipleChoiceQuestion':
                    $digestedMessage = $this->digestFromApiMultipleChoiceQuestion($msg, $lastUserQuestion);
                    break;
                case 'extendedContentsAnswer':
                    $digestedMessage = $this->digestFromApiExtendedContentsAnswer($msg, $lastUserQuestion);
                    break;
            }
            if (count($digestedMessage) > 0) {
                $output[] = $digestedMessage;
            }
        }
        return $output;
    }

    /**
     **	Classifies the API message into one of the defined $apiMessageTypes
     **/
    protected function checkApiMessageType($message)
    {
        $responseType = null;
        foreach ($this->apiMessageTypes as $type) {
            switch ($type) {
                case 'answer':
                    $responseType = $this->isApiAnswer($message) ? $type : null;
                    break;
                case 'polarQuestion':
                    $responseType = $this->isApiPolarQuestion($message) ? $type : null;
                    break;
                case 'multipleChoiceQuestion':
                    $responseType = $this->isApiMultipleChoiceQuestion($message) ? $type : null;
                    break;
                case 'extendedContentsAnswer':
                    $responseType = $this->isApiExtendedContentsAnswer($message) ? $type : null;
                    break;
            }
            if (!is_null($responseType)) {
                return $responseType;
            }
        }
        throw new Exception("Unknown ChatbotAPI response: " . json_encode($message, true));
    }

    /********************** API MESSAGE TYPE CHECKERS **********************/

    protected function isApiAnswer($message)
    {
        return isset($message->type) && $message->type == 'answer';
    }

    protected function isApiPolarQuestion($message)
    {
        return isset($message->type) && $message->type == "polarQuestion";
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return isset($message->type) && $message->type == "multipleChoiceQuestion";
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return isset($message->type) && $message->type == "extendedContentsAnswer";
    }

    protected function hasTextMessage($message)
    {
        return isset($message->message) && is_string($message->message);
    }


    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message, $lastUserQuestion)
    {
        $messageTxt = $message->message;

        if (isset($message->attributes->SIDEBUBBLE_TEXT) && !empty($message->attributes->SIDEBUBBLE_TEXT)) {
            $messageTxt .= "\n" . $message->attributes->SIDEBUBBLE_TEXT;
        }

        $output = $this->handleMessageWithImgOrIframe($messageTxt);
        $this->handleMessageWithActionField($message, $messageTxt, $lastUserQuestion);
        $this->handleMessageWithRelatedContent($message, $messageTxt, $lastUserQuestion);
        $this->handleMessageWithLinks($messageTxt);
        $this->handleMessageWithTextFormat($messageTxt, $this->isWhatsappRequest);

        // Add simple text-answer
        $output["body"] = $this->formatFinalMessage($messageTxt);

        return $output;
    }


    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, $isPolar = false)
    {
        $output = [
            "body" => $this->formatFinalMessage($message->message),
        ];

        $options = $message->options;

        foreach ($options as $i => &$option) {
            $option->opt_key = $i + 1;
            if (isset($option->attributes->title) && !$isPolar) {
                $option->title = $option->attributes->title;
            } elseif ($isPolar) {
                $option->is_polar = true;
            }
            $output['body'] .= "\n" . $option->opt_key . ') ' . $option->label;
        }
        $this->session->set('options', $options);
        $this->session->set('lastUserQuestion', $lastUserQuestion);

        return $output;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        return $this->digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, true);
    }


    protected function digestFromApiExtendedContentsAnswer($message, $lastUserQuestion)
    {
        $output = [
            "body" => $this->formatFinalMessage("_" . $message->message . "_"),
        ];

        $messageTitle = [];
        $messageExtended = [];
        $hasUrl = false;

        foreach ($message->subAnswers as $index => $subAnswer) {

            $messageTitle[$index] = $subAnswer->message;

            if (!isset($messageExtended[$index])) $messageExtended[$index] = [];

            if (isset($subAnswer->parameters) && isset($subAnswer->parameters->contents)) {
                if (isset($subAnswer->parameters->contents->url)) {
                    $messageExtended[$index][] = " (" . $subAnswer->parameters->contents->url->value . ")\n";
                    $hasUrl = true;
                }
            }
        }

        $messageTmp = "";
        if ($hasUrl) {
            foreach ($messageTitle as $index => $mt) {
                $messageTmp .= "\n\n" . $mt;
                foreach ($messageExtended[$index] as $key => $me) {
                    $messageTmp .= ($key == 0 ? "\n\n" : "") . $me;
                }
            }
        } else {
            if (count($messageTitle) == 1) {
                $tmp = $this->digestFromApiAnswer($message->subAnswers[0], $lastUserQuestion);
                $messageTmp = "\n\n" . $tmp["body"];
            } else if (count($messageTitle) > 1) {
                $messageTmp = "\n";
                foreach ($messageTitle as $index => $mt) {
                    $messageTmp .= "\n" . ($index + 1) . ") " . $mt;
                }
                $this->session->set('federatedSubanswers', $message->subAnswers);
            }
        }
        $output["body"] .=  $this->formatFinalMessage($messageTmp);

        return $output;
    }


    /********************** MISC **********************/

    /**
     * Create the content for ratings
     */
    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        $message = $this->langManager->translate('rate_content_intro') . "\n";
        foreach ($ratingOptions as $index => $option) {
            $message .= "\n" . ($index + 1) . ") " . $this->langManager->translate($option['label']);
        }
        $output["body"] = $message;
        return $output;
    }


    /**
     * Validate if the message has images or iframes
     */
    public function handleMessageWithImgOrIframe(&$messageTxt)
    {
        $output = [];
        if (strpos($messageTxt, '<img') !== false || strpos($messageTxt, '<iframe') !== false) {
            $mediaElements = [];
            if (strpos($messageTxt, '<img') !== false) {
                $tmp = $this->handleMessageWithImages($messageTxt);
                foreach ($tmp as $element) {
                    $mediaElements[] = $element;
                }
            }
            if (strpos($messageTxt, '<iframe') !== false) {
                $tmp = $this->handleMessageWithIframe($messageTxt);
                foreach ($tmp as $element) {
                    $mediaElements[] = $element;
                }
            }
            if (count($mediaElements) > 0) {
                $output['extra'] = [
                    "mediaUrl" => []
                ];
                foreach ($mediaElements as $element) {
                    $output['extra']["mediaUrl"][] = $element;
                }
            }
        }
        return $output;
    }

    /**
     * Validate if the message has action fields
     */
    private function handleMessageWithActionField($message, &$messageTxt, $lastUserQuestion)
    {
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $options = $this->handleMessageWithListValues($message->actionField->listValues, $lastUserQuestion);
                if ($options !== "") {
                    $messageTxt .= " (type a number)";
                    $messageTxt .= $options;
                }
            } else if ($message->actionField->fieldType === 'datePicker') {
                $messageTxt .= " (date format: mm/dd/YYYY)";
            }
        }
    }

    /**
     * Validate if the message has related content and put like an option list
     */
    private function handleMessageWithRelatedContent($message, &$messageTxt, $lastUserQuestion)
    {
        if (isset($message->parameters->contents->related->relatedContents) && !empty($message->parameters->contents->related->relatedContents)) {
            $messageTxt .= "\r\n \r\n" . $message->parameters->contents->related->relatedTitle . " (type a number)";

            $options = [];
            $optionList = "";
            foreach ($message->parameters->contents->related->relatedContents as $key => $relatedContent) {
                $options[$key] = (object) [];
                $options[$key]->opt_key = $key + 1;
                $options[$key]->related_content = true;
                $options[$key]->label = $relatedContent->title;
                $optionList .= "\n\n" . ($key + 1) . ') ' . $relatedContent->title;
            }
            if ($optionList !== "") {
                $messageTxt .= $optionList;
                $this->session->set('hasRelatedContent', true);
                $this->session->set('options', (object) $options);
                $this->session->set('lastUserQuestion', $lastUserQuestion);
            }
        }
    }

    /**
     *	Splits a message that contains an <img> tag into text/image/text and displays them in Twilio
     */
    protected function handleMessageWithImages($message)
    {
        //Remove \t \n \r and HTML tags (keeping <img> tags)
        $text = str_replace(["\r\n", "\r", "\n", "\t"], '', strip_tags($message, "<img>"));
        //Capture all IMG tags and return an array with [text,imageURL,text,...]
        $parts = preg_split('/<\s*img.*?src\s*=\s*"(.+?)".*?\s*\/?>/', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $images = [];
        for ($i = 0; $i < count($parts); $i++) {

            if (substr($parts[$i], 0, 4) == 'http') {
                $images[] = $parts[$i];
            }
        }
        return $images;
    }

    /**
     * Extracts the url from the iframe
     */
    private function handleMessageWithIframe(&$messageTxt)
    {
        //Remove \t \n \r and HTML tags (keeping <iframe> tags)
        $text = str_replace(["\r\n", "\r", "\n", "\t"], '', strip_tags($messageTxt, "<iframe>"));
        //Capture all IFRAME tags and return an array with [text,imageURL,text,...]
        $parts = preg_split('/<\s*iframe.*?src\s*=\s*"(.+?)".*?\s*\/?>/', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $elements = [];
        for ($i = 0; $i < count($parts); $i++) {
            if (substr($parts[$i], 0, 4) == 'http') {
                $urlElements = explode(".", $parts[$i]);
                $fileFormat = $urlElements[count($urlElements) - 1];
                if (in_array($fileFormat, $this->attachableFormats)) {
                    $elements[] = $parts[$i];
                } else {
                    $pos1 = strpos($messageTxt, "<iframe");
                    $pos2 = strpos($messageTxt, "</iframe>", $pos1);
                    $iframe = substr($messageTxt, $pos1, $pos2 - $pos1 + 9);
                    $messageTxt = str_replace($iframe, "<a href='" . $parts[$i] . "'></a>", $messageTxt);
                }
            }
        }
        return $elements;
    }

    /**
     * Remove the common html tags from the message and set the final message
     */
    public function formatFinalMessage($message)
    {
        $message = str_replace('&nbsp;', ' ', $message);
        $message = str_replace("\u{00a0}", ' ', $message);
        $message = str_replace(["\t"], '', $message);

        $breaks = array("<br />", "<br>", "<br/>", "<p>");
        $message = str_ireplace($breaks, "\n", $message);

        $message = strip_tags($message);

        $rows = explode("\n", $message);
        $messageProcessed = "";
        $previousJump = 0;
        foreach ($rows as $row) {
            if ($row == "" && $previousJump == 0) {
                $previousJump++;
            } else if ($row == "" && $previousJump == 1) {
                $previousJump++;
                $messageProcessed .= "\r\n";
            }
            if ($row !== "") {
                $messageProcessed .= $row . "\r\n";
                $previousJump = 0;
            }
        }
        $messageProcessed = str_replace("  ", " ", $messageProcessed);
        return $messageProcessed;
    }

    /**
     * Set the options for message with list values
     */
    protected function handleMessageWithListValues($listValues, $lastUserQuestion)
    {
        $optionList = "";
        $options = $listValues->values;
        foreach ($options as $i => &$option) {
            $option->opt_key = $i + 1;
            $option->list_values = true;
            $option->label = $option->option;
            $optionList .= "\n" . $option->opt_key . ') ' . $option->label;
        }
        if ($optionList !== "") {
            $this->session->set('options', $options);
            $this->session->set('lastUserQuestion', $lastUserQuestion);
        }
        return $optionList;
    }


    /**
     * Format the link as part of the message
     */
    public function handleMessageWithLinks(&$messageTxt)
    {
        if ($messageTxt !== "") {
            $dom = new \DOMDocument();
            $dom->loadHTML($messageTxt);
            $nodes = $dom->getElementsByTagName('a');

            $urls = [];
            $value = [];
            foreach ($nodes as $node) {
                $urls[] = $node->getAttribute('href');
                $value[] = trim($node->nodeValue);
            }

            if (strpos($messageTxt, '<a ') !== false && count($urls) > 0) {
                $countLinks = substr_count($messageTxt, "<a ");
                $lastPosition = 0;
                for ($i = 0; $i < $countLinks; $i++) {
                    $firstPosition = strpos($messageTxt, "<a ", $lastPosition);
                    $lastPosition = strpos($messageTxt, "</a>", $firstPosition);

                    if (isset($urls[$i]) && $lastPosition > 0) {
                        $aTag = substr($messageTxt, $firstPosition, $lastPosition - $firstPosition + 4);
                        $textToReplace = $value[$i] !== "" ? $value[$i] . " (" . $urls[$i] . ")" : $urls[$i];
                        $messageTxt = str_replace($aTag, $textToReplace, $messageTxt);
                    }
                }
            }
        }
    }

    /**
     * Format the text if is bold, italic or strikethrough (only for Whatsapp)
     */
    public function handleMessageWithTextFormat(&$messageTxt, $isWhatsappRequest = false)
    {
        if ($isWhatsappRequest) {
            $tagsAccepted = ['strong', 'b', 'em', 's'];
            foreach ($tagsAccepted as $tag) {
                if (strpos($messageTxt, '<' . $tag . '>') !== false) {

                    $replaceChar = "*"; //*bold*
                    if ($tag === "em") $replaceChar = "_"; //_italic_
                    else if ($tag === "s") $replaceChar = "~"; //~strikethrough~

                    $countTags = substr_count($messageTxt, "<" . $tag . ">");

                    $lastPosition = 0;
                    $tagArray = [];
                    for ($i = 0; $i < $countTags; $i++) {
                        $firstPosition = strpos($messageTxt, "<" . $tag . ">", $lastPosition);
                        $lastPosition = strpos($messageTxt, "</" . $tag . ">", $firstPosition);
                        if ($lastPosition > 0) {
                            $tagLength = strlen($tag) + 3;
                            $tagArray[] = substr($messageTxt, $firstPosition, $lastPosition - $firstPosition + $tagLength);
                        }
                    }
                    foreach ($tagArray as $oldTag) {
                        $newTag = str_replace("<" . $tag . ">", "", $oldTag);
                        $newTag = str_replace("</" . $tag . ">", "", $newTag);
                        $newTag = $replaceChar . trim($newTag) . $replaceChar . " ";
                        $messageTxt = str_replace($oldTag, $newTag, $messageTxt);
                    }
                }
            }
        }
    }

    /**
     *	Disabled for Twilio
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {
        $output = [];
        return $output;
    }

    /**
     * Build the message and options to escalate
     * @return array
     */
    public function buildEscalationMessage()
    {
        $escalateOptions = [
            (object) [
                "label" => 'yes',
                "escalate" => true,
                "opt_key" => 1
            ],
            (object) [
                "label" => 'no',
                "escalate" => false,
                "opt_key" => 2
            ],
        ];

        $this->session->set('options', (object) $escalateOptions);
        $message = $this->langManager->translate('ask_to_escalate') . "\n";
        $message .= "1) " . $this->langManager->translate('yes') . "\n";
        $message .= "2) " . $this->langManager->translate('no');
        $output['body'] = $message;

        return $output;
    }

    /**
     * Check if Hyperchat is running and if the attached file is correct
     * @param array $request
     * @return array $output
     */
    protected function mediaFileToHyperchat(array $request)
    {
        $output = ['message' => ''];
        if ($this->session->get('chatOnGoing', false)) {
            $mediaFile = $this->getMediaFile($request['MediaUrl0'], $request['MediaContentType0']);
            if ($mediaFile !== "") {
                $output = ['media' => $mediaFile];
            }
        }
        return $output;
    }

    /**
     * Get the media file in from the Twilio response, 
     * save file into temporal directory to sent to Hyperchat
     * @param string $fileUrl
     * @param string $mediaContent
     */
    protected function getMediaFile(string $fileUrl, string $mediaContent)
    {
        $format = explode("/", $mediaContent);
        if (isset($format[1]) && in_array($format[1], $this->attachableFormats) ) {
            $fileName = sys_get_temp_dir(). "/file.".$format[1];
            $realUrl = $this->getRealUrl($fileUrl);
            $tmpFile = fopen($fileName, "w") or die;
            fwrite($tmpFile, file_get_contents($realUrl));
            
            return fopen($fileName, 'r');
        }
        return "";
    }

    /**
     * Media files from Twilio are not showing with the direct URL
     * With curl we get the URL of the redirection for the real URL
     * @param string $fileUrl
     * @return string $realUrl
     */
    protected function getRealUrl(string $fileUrl)
    {
        $realUrl = "";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        $result = curl_exec($ch);
        if (preg_match('#Location: (.*)#', $result, $uri)) {
            $realUrl = isset($uri[1]) ? trim($uri[1]) : "";
        }
        return $realUrl;
    }
}
