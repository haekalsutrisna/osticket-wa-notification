<?php

require_once(INCLUDE_DIR.'class.signal.php');
require_once(INCLUDE_DIR.'class.plugin.php');
require_once('config.php');

class WhatsappPlugin extends Plugin {
    var $config_class = "WAPluginConfig"; // You may want to change the config class to reflect WhatsApp settings

    function bootstrap() {
        Signal::connect('ticket.created', array($this, 'onTicketCreated'), 'Ticket');
        error_log('WhatsappPlugin bootstrap connected to ticket.created signal');
    }

    function onTicketCreated($ticket) {
        // Log that the function is triggered
        error_log('onTicketCreated called with ticket ID: ' . $ticket->getId());

        global $ost;
        $ticketLink = $ost->getConfig()->getUrl() . '/scp/tickets.php?id=' . $ticket->getId();
        $ticketId = $ticket->getNumber();
        $title = $ticket->getSubject() ?: 'No subject';
        $createdBy = $ticket->getName() . " (" . $ticket->getEmail() . ")";

        // Fetch the Help Topic
        $helpTopic = $ticket->getTopic() ? $ticket->getTopic()->getName() : 'No help topic';

        // Log ticket details
        error_log('Ticket details: ID = ' . $ticketId . ', Created by = ' . $createdBy . ', Subject = ' . $title . ', Help Topic = ' . $helpTopic);

        // Fetch the last message of the ticket
        $messageObj = $ticket->getLastMessage();
        $body = $messageObj ? $messageObj->getMessage() : 'No content';

        // Escape the message for HTML
        $body = strip_tags($body);

        // Fetch custom form data
        $formData = $this->getTicketFormData($ticket);

        // Get Issue Summary, NIK, Department, and Location
        $issueSummary = $this->getCustomField($ticket, 'Issue Summary');
        $nik = $this->getCustomField($ticket, 'NIK (Work Number)');
        $department = $this->getChoiceField($ticket, 'Department');
        $location = $this->getChoiceField($ticket, 'Specific Location (Sub Department)');
        $ipAddress = $ticket->getIP();

        // Get phone number from form data (assuming 'Phone Number' is the label in the form)
        $phoneNumber = $this->getPhoneNumberFromFormData($ticket);

        $appkey = 'YOUR-APP-KEY';
        $authkey = 'YOUR-API-TOKEN';
        $recipients = ['RECEPIENT-NUMBER'];
        // Prepare message content for WhatsApp
        $message = "*New Ticket: #" . $this->escapeHtml($ticketId) . "*\n"
                 . "Created by: " . $this->escapeHtml($createdBy) . "\n"
                 . "Help Topic: " . $this->escapeHtml($helpTopic) . "\n\n"
                 . "*Ticket Details:* " . "\n"
                 . "Subject:\n " . $this->escapeHtml($issueSummary) . "\n"
                 . ($body ? "Message:\n " . $body . "\n\n" : '') 
                 . "*Contact Information:* " . "\n"
                 . ($phoneNumber ? "Phone/WA: wa.me/" . $this->escapeHtml($phoneNumber) . "\n" : '')
                 . "NIK: " . $this->escapeHtml($nik) . "\n"
                 . "Department: " . $this->escapeHtml($department) . "\n"
                 . "Location: " . $this->escapeHtml($location) . "\n\n"
                 . "*Ticket Link:*\n " . $this->escapeHtml($ticketLink) . "\n";

        // Prepare payload for saungwa API
        foreach ($recipients as $to) {
            $payload = array(
                'appkey' => $appkey,
                'authkey' => $authkey,
                'to' => $to, // Send to one recipient at a time
                'message' => $message
            );

        // Log the payload
        error_log('Payload to be sent to saungwa: ' . json_encode($payload));

        // Send payload to saungwa API
        $this->sendToSaungwa($payload);

        }
    }

    // Function to send the payload to saungwa API
    function sendToSaungwa($payload) {
        try {
            $data_string = http_build_query($payload);
            $url = 'https://app.saungwa.com/api/create-message'; // Saungwa API endpoint

            // Log the URL being used
            error_log('Sending to Saungwa API using URL: ' . $url);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($data_string)
            ));

            $result = curl_exec($ch);

            // Log the result of curl_exec
            if ($result === false) {
                throw new Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                error_log('Curl result: ' . $result . ' | Status code: ' . $statusCode);

                if ($statusCode != '200') {
                    throw new Exception($url . ' Http code: ' . $statusCode);
                }
            }

            curl_close($ch);
        } catch (Exception $e) {
            error_log('Error posting to Saungwa API: ' . $e->getMessage());
        }
    }

    // Function to fetch all form data for the ticket
    function getTicketFormData($ticket) {
        $formData = "";
        $entries = DynamicFormEntry::forTicket($ticket->getId()); // Fetch form entries for the ticket

        foreach ($entries as $entry) {
            $answers = $entry->getAnswers();
            foreach ($answers as $answer) {
                $formData .= "<b>" . $this->escapeHtml($answer->getField()->get('label')) . ":</b> " . $this->escapeHtml($answer->getValue()) . "\n";
            }
        }

        return $formData;
    }

    // Function to fetch a specific custom field by label
    function getCustomField($ticket, $label) {
        $entries = DynamicFormEntry::forTicket($ticket->getId()); // Fetch form entries for the ticket

        foreach ($entries as $entry) {
            $answers = $entry->getAnswers();
            foreach ($answers as $answer) {
                if (strtolower($answer->getField()->get('label')) == strtolower($label)) {
                    return $answer->getValue();
                }
            }
        }

        return 'Not provided';
    }

    // Function to fetch Department 
    function getChoiceField($ticket, $label) {
        $entries = DynamicFormEntry::forTicket($ticket->getId());
    
        foreach ($entries as $entry) {
            $answers = $entry->getAnswers();
            foreach ($answers as $answer) {
                if (strtolower($answer->getField()->get('label')) == strtolower($label)) {
                    // Get the actual field value (ID or label stored in the form)
                    $choiceValue = $answer->getValue();
                    
                    // Get the field itself to retrieve its choices (if available)
                    $field = $answer->getField();
                    if ($field && method_exists($field, 'getChoices')) {
                        $choices = $field->getChoices(); // Use the choices associated with the field
                        return isset($choices[$choiceValue]) ? $choices[$choiceValue] : $choiceValue;
                    }
                    return $choiceValue;
                }
            }
        }
    
        return 'Not provided';
    }

    // Function to fetch the phone number from form data
    function getPhoneNumberFromFormData($ticket) {
        $entries = DynamicFormEntry::forTicket($ticket->getId()); // Fetch form entries for the ticket

        foreach ($entries as $entry) {
            $answers = $entry->getAnswers();
            foreach ($answers as $answer) {
                if (strtolower($answer->getField()->get('label')) == 'phone number') {
                    $phoneNumber = $answer->getValue();
                    // Replace leading 0 with 62 for Indonesian numbers
                    if (strpos($phoneNumber, '0') === 0) {
                        return '62' . substr($phoneNumber, 1);
                    }
                    return $phoneNumber;
                }
            }
        }

        return 'Not provided';
    }

    // Function to escape HTML characters in the message
    function escapeHtml($value) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

?>
