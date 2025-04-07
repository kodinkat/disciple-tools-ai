/**
 * Magic Link JavaScript for AI Chat Control
 */
jQuery(document).ready(function($) {
    let jsObject;
    
    // Initialize the jsObject from PHP data
    if (typeof window.dt_magic_link_data !== 'undefined') {
        jsObject = window.dt_magic_link_data;
    }
    
    // Set title
    $('#title').text('AI Chat Control');
    
    // Initialize the chat container
    let chatContainer = $('<div id="chat-container"></div>');
    $('#content').prepend(chatContainer);
    
    // Function to scroll chat to bottom
    function scrollToBottom() {
        chatContainer.scrollTop(chatContainer[0].scrollHeight);
    }
    
    // Message display function
    function displayMessage(text, isUser = false, contactData = null) {
        const messageClass = isUser ? 'user-message' : 'system-message';
        
        // Create basic message container
        const messageHtml = `<div class="message-content">${text}</div>`;
        
        // Create message wrapper
        const messageWrapper = $('<div class="message-wrapper"></div>');
        const message = $(`<div class="${messageClass}">${messageHtml}</div>`);
        messageWrapper.append(message);
        
        // Add contact link button if contact data is provided
        if (contactData && contactData.id) {
            const linkButton = $(`
                <a href="${jsObject.site_url}/contacts/${contactData.id}" target="_blank" class="contact-link-btn">
                    <i class="mdi mdi-account-arrow-right"></i>
                </a>
            `);
            messageWrapper.append(linkButton);
        }
        
        chatContainer.append(messageWrapper);
        scrollToBottom();
    }
    
    // Display welcome message
    displayMessage("Welcome to AI Chat Control. You can type or speak commands like 'I met with Mary on Thursday and we discussed her Bible reading. She is growing in faith.' to update contact records with meeting details, notes, faith status, and more.");
    
    // Handle text input submission
    $('#submit-btn').on('click', function() {
        processUserInput();
    });
    
    // Make sure submit button has the correct text
    $('#submit-btn').html('<i class="mdi mdi-send"></i>');
    
    // Initialize voice recording functionality
    let isRecording = false;
    let mediaRecorder = null;
    let audioChunks = [];
    
    // Handle voice button click
    $('#voice-btn').on('click', function() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            // Stop recording
            mediaRecorder.stop();
            displayMessage("Processing your voice input...");
            $(this).html('<i class="mdi mdi-microphone"></i>');
        } else {
            // Start recording
            startRecording();
            $(this).html('<i class="mdi mdi-stop"></i>');
        }
    });
    
    // Start recording audio
    function startRecording() {
        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function(stream) {
                audioChunks = [];
                mediaRecorder = new MediaRecorder(stream);
                
                mediaRecorder.addEventListener('dataavailable', function(event) {
                    audioChunks.push(event.data);
                });
                
                mediaRecorder.addEventListener('stop', function() {
                    const audioBlob = new Blob(audioChunks, { type: 'audio/wav' });
                    processAudioTranscription(audioBlob);
                    
                    // Stop all audio tracks
                    stream.getAudioTracks().forEach(track => track.stop());
                });
                
                mediaRecorder.start();
                displayMessage("Listening... Click the microphone button again to stop recording.");
            })
            .catch(function(error) {
                console.error('Error accessing microphone:', error);
                displayMessage("Error accessing microphone. Please ensure your microphone is connected and you've granted permission to use it.");
            });
    }
    
    // Process audio transcription
    function processAudioTranscription(audioBlob) {
        transcribeAudio(audioBlob)
            .then(function(text) {
                if (text) {
                    $('#text-input').val(text);
                    displayMessage("Transcription: " + text);
                } else {
                    displayMessage("Couldn't transcribe audio. Please try again or type your message.");
                }
                $('#voice-btn').html('<i class="mdi mdi-microphone"></i>');
            })
            .catch(function(error) {
                console.error("Error in transcription:", error);
                displayMessage("Error transcribing audio: " + error);
                $('#voice-btn').html('<i class="mdi mdi-microphone"></i>');
            });
    }
    
    // Transcribe audio using Prediction Guard API through our backend endpoint
    function transcribeAudio(audioBlob) {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'recording.webm');
        //add parts to form data
        formData.append('parts', JSON.stringify(jsObject.parts));
        
        $.ajax({
            url: jsObject.root + 'ai/v1/control/transcribe',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', jsObject.nonce);
            },
            success: function(response) {
                // Remove processing message
                $('.system-message:last').remove();
                
                if (response.success && response.text) {
                    // Set the transcribed text in the input field
                    $('#text-input').val(response.text);
                    displayMessage("Transcribed: " + response.text);
                } else {
                    displayMessage("Failed to transcribe audio.");
                }
            },
            error: function(xhr, status, error) {
                // Remove processing message
                $('.system-message:last').remove();
                
                console.error('Transcription error:', xhr.responseText);
                displayMessage("Error transcribing audio. Please try again.");
            }
        });
    }
    
    $('#text-input').on('keypress', function(e) {
        if (e.which === 13) {
            processUserInput();
        }
    });
    
    function processUserInput() {
        const userInput = $('#text-input').val().trim();
        
        if (!userInput) {
            return;
        }
        
        // Display user message
        displayMessage(userInput, true);
        
        // Clear input field
        $('#text-input').val('');
        
        // Show loading indicator
        displayMessage("Processing...");
        
        // Send command to API
        sendChatCommand(userInput);
    }
    
    function sendChatCommand(command) {
        $.ajax({
            url: jsObject.root + 'ai/v1/control/go',
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({
                command: command,
                parts: jsObject.parts,
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', jsObject.nonce);
            },
            success: function(response) {
                // Remove loading message
                $('.system-message:last').remove();
                
                console.log('Response received:', response);
                
                if (response.success) {
                    if (response.ambiguous && response.contacts && response.contacts.length > 0) {
                        // Display message about multiple contacts
                        displayMessage(response.message);
                        
                        // Create a container for contact options
                        const selectionContainer = $('<div class="system-message contact-selection-container"></div>');
                        const optionsDiv = $('<div class="contact-selection-options"></div>');
                        
                        // Add each contact as a button
                        response.contacts.forEach(function(contact) {
                            const buttonText = contact.name + ' (' + contact.id + ')' + (contact.details ? ' ' + contact.details : '');
                            const button = $('<button class="contact-select-btn"></button>')
                                .text(buttonText)
                                .attr('data-id', contact.id);
                                
                            button.on('click', function() {
                                handleContactSelection($(this).attr('data-id'), response.original_command);
                            });
                            
                            optionsDiv.append(button);
                        });
                        
                        // Add the options to the container and append to chat
                        selectionContainer.append(optionsDiv);
                        chatContainer.append(selectionContainer);
                        scrollToBottom();
                    } else {
                        displayMessage(response.message, false, response.contact_data);
                    }
                } else {
                    let errorMessage = response.message || "I couldn't understand that command.";
                    displayMessage(errorMessage);
                    
                    // Provide helpful suggestions
                    if (errorMessage.includes("not found")) {
                        displayMessage("Try using the full name of the contact, or check if the contact exists in the system.");
                    } else {
                        displayMessage("Try something like 'I met with [contact name] yesterday and we talked about [topic]' or 'Had a meeting with [contact name] about [details]. They are now reading the Bible regularly and want to be baptized next Sunday (YYYY-MM-DD).'");
                    }
                }
            },
            error: function(xhr, status, error) {
                // Remove loading message
                $('.system-message:last').remove();
                
                console.error('Error details:', xhr.responseText);
                
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse && errorResponse.message) {
                        displayMessage("Error: " + errorResponse.message);
                    } else {
                        displayMessage("Something went wrong. Please try again.");
                    }
                } catch (e) {
                    displayMessage("Something went wrong. Please try again.");
                }
                
                displayMessage("If the problem persists, try refreshing the page or contact support.");
            }
        });
    }
    
    // Handle when a user selects a specific contact
    function handleContactSelection(contactId, originalCommand) {
        console.log('Selected contact ID:', contactId);
        console.log('Original command:', originalCommand);
        
        // Remove the selection options
        $('.contact-selection-container').remove();
        scrollToBottom();
        
        // Show loading indicator
        displayMessage("Processing...");
        
        // Send the command again with the selected contact
        $.ajax({
            url: jsObject.root + 'ai/v1/control/go',
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({
                command: originalCommand,
                contact_selection: contactId,
                parts: jsObject.parts
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', jsObject.nonce);
            },
            success: function(finalResponse) {
                // Remove loading message
                $('.system-message:last').remove();
                
                if (finalResponse.success) {
                    displayMessage(finalResponse.message, false, finalResponse.contact_data);
                } else {
                    displayMessage("Error: " + (finalResponse.message || "Failed to update contact"));
                }
            },
            error: function(xhr) {
                // Remove loading message
                $('.system-message:last').remove();
                
                displayMessage("Error: Failed to update contact");
            }
        });
    }
    
    // Fetch data from API
    window.get_chat_control_data = () => {
        jQuery.ajax({
            type: "GET",
            data: { action: 'get', parts: jsObject.parts },
            contentType: "application/json; charset=utf-8",
            dataType: "json",
            url: jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type,
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', jsObject.nonce)
            }
        })
        .done(function(data) {
            window.load_chat_control_data(data);
        })
        .fail(function(e) {
            console.log(e);
            jQuery('#error').html(e);
        });
    };
    
    // Process and display chat control data
    window.load_chat_control_data = (data) => {
        let content = jQuery('#api-content');
        let spinner = jQuery('.loading-spinner');

        content.empty();
        let html = ``;
        
        if (Array.isArray(data) && data.length > 0) {
            data.forEach(item => {
                html += `
                    <div class="cell">
                        ${window.lodash.escape(item.name)}
                    </div>
                `;
            });
        } else {
            html = `<div class="cell">No chat control data available</div>`;
        }
        
        content.html(html);
        spinner.removeClass('active');
    };
    
}); 