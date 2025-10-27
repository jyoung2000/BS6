/**
 * Box AI Chat - Modern Apple-inspired Frontend
 * Clean, modern interactions following Apple design principles
 */

(function($) {
    'use strict';

    console.log('Box AI Chat (Apple Design) script loaded');

    // Wait for DOM to be ready
    $(document).ready(function() {
        console.log('DOM ready, initializing Box AI Chat...');

        // Cache DOM elements
        var $wrapper = $('#box-ai-chat-modal');
        var $backdrop = $('.box-ai-chat-backdrop');
        var $container = $('.box-ai-chat-container');
        var $chatButton = $('#box-ai-chat-button');
        var $closeButton = $('#box-ai-chat-close');
        var $messagesContainer = $('#box-ai-chat-messages');
        var $inputField = $('#box-ai-chat-input');
        var $sendButton = $('#box-ai-chat-send');

        console.log('Elements found:');
        console.log('- Modal wrapper:', $wrapper.length);
        console.log('- Chat button:', $chatButton.length);
        console.log('- Messages container:', $messagesContainer.length);

        if ($chatButton.length === 0) {
            console.error('Chat button not found! Make sure you are on a document viewer page.');
            return;
        }

        var fileId = $chatButton.data('file-id');
        console.log('File ID from button:', fileId);

        if (!fileId) {
            console.error('No file ID found on chat button!');
            return;
        }

        var isProcessing = false;
        var summaryLoaded = false;

        console.log('Box AI Chat initialized successfully');

        // Open modal with smooth animation
        $chatButton.on('click', function(e) {
            e.preventDefault();
            console.log('Chat button clicked. Summary loaded:', summaryLoaded);

            // Show modal with animation
            $wrapper.fadeIn(300, function() {
                console.log('Modal is now visible');
                
                // Add animation class for container
                $container.addClass('animate-in');

                // Auto-generate summary on first open
                if (!summaryLoaded) {
                    console.log('Starting auto-summary generation...');
                    setTimeout(function() {
                        generateSummary();
                    }, 400); // Wait for animation to complete
                } else {
                    console.log('Summary already loaded, focusing input');
                    $inputField.focus();
                }
            });
        });

        // Close modal with animation
        function closeModal() {
            $wrapper.attr('data-state', 'closing');

            setTimeout(function() {
                $wrapper.fadeOut(300, function() {
                    $wrapper.removeAttr('data-state');
                    $container.removeClass('animate-in');
                });
            }, 300);
        }

        // Modal close button just closes the modal
        $closeButton.on('click', closeModal);
        $backdrop.on('click', closeModal);

        // Close on Escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $wrapper.is(':visible')) {
                closeModal();
            }
        });

        // Auto-resize textarea and update button state
        $inputField.on('input', function() {
            // Auto-resize
            this.style.height = 'auto';
            var newHeight = Math.min(this.scrollHeight, 120);
            this.style.height = newHeight + 'px';

            // Toggle button active state based on input
            var hasText = $(this).val().trim().length > 0;
            if (hasText) {
                $sendButton.addClass('active');
            } else {
                $sendButton.removeClass('active');
            }
        });

        // Send message on Enter (Shift+Enter for new line)
        $inputField.on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Send message on button click
        $sendButton.on('click', sendMessage);

        function sendMessage() {
            var message = $inputField.val().trim();

            if (!message || isProcessing) {
                return;
            }

            // Remove welcome message with fade animation
            $('.box-ai-chat-welcome').fadeOut(300, function() {
                $(this).remove();
            });

            // Add user message
            addMessage(message, 'user');

            // Clear and reset input
            $inputField.val('').css('height', '48px');
            $sendButton.removeClass('active');

            // Show typing indicator
            var $typingIndicator = createTypingIndicator();
            $messagesContainer.append($typingIndicator);
            scrollToBottom();

            // Disable sending
            isProcessing = true;
            $sendButton.prop('disabled', true).addClass('disabled');
            $inputField.prop('disabled', true);

            // Send AJAX request
            $.ajax({
                url: boxAiChat.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'box_ai_chat',
                    nonce: boxAiChat.nonce,
                    file_id: fileId,
                    message: message
                },
                success: function(response) {
                    $typingIndicator.remove();

                    if (response.success) {
                        addMessage(response.data.answer, 'ai');
                    } else {
                        addMessage('I encountered an error: ' + (response.data || 'Unknown error'), 'ai', true);
                    }
                },
                error: function(xhr, status, error) {
                    $typingIndicator.remove();
                    addMessage('Connection error. Please try again.', 'ai', true);
                },
                complete: function() {
                    isProcessing = false;
                    $sendButton.prop('disabled', false).removeClass('disabled');
                    $inputField.prop('disabled', false).focus();
                }
            });
        }

        function generateSummary() {
            console.log('generateSummary() called');

            // Remove welcome message with animation
            var $welcomeMsg = $('.box-ai-chat-welcome');
            console.log('Welcome messages found:', $welcomeMsg.length);
            
            $welcomeMsg.fadeOut(300, function() {
                $(this).remove();
            });

            // Show typing indicator
            var $typingIndicator = createTypingIndicator();
            $messagesContainer.append($typingIndicator);
            scrollToBottom();

            // Disable input while loading
            isProcessing = true;
            $sendButton.prop('disabled', true).addClass('disabled');
            $inputField.prop('disabled', true);

            console.log('Sending AJAX request for summary...');

            // Send AJAX request for summary
            $.ajax({
                url: boxAiChat.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'box_ai_chat',
                    nonce: boxAiChat.nonce,
                    file_id: fileId,
                    message: 'Please provide a comprehensive summary of this document, including the main topics, key points, and any important conclusions or recommendations.'
                },
                success: function(response) {
                    console.log('Summary AJAX response received:', response);
                    $typingIndicator.remove();

                    if (response.success) {
                        console.log('Summary generated successfully');
                        addMessage(response.data.answer, 'ai');
                        summaryLoaded = true;
                    } else {
                        console.error('Summary generation failed:', response.data);
                        addMessage('Unable to generate summary: ' + (response.data || 'Unknown error'), 'ai', true);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Summary AJAX error:', status, error);
                    $typingIndicator.remove();
                    addMessage('Failed to generate summary. Please try asking a specific question.', 'ai', true);
                },
                complete: function() {
                    console.log('Summary request completed');
                    isProcessing = false;
                    $sendButton.prop('disabled', false).removeClass('disabled');
                    $inputField.prop('disabled', false).focus();
                }
            });
        }

        function createTypingIndicator() {
            return $('<div class="box-ai-chat-message box-ai-chat-message-ai">')
                .append(
                    $('<div class="box-ai-chat-typing-indicator">')
                        .append('<span class="box-ai-chat-typing-dot"></span>')
                        .append('<span class="box-ai-chat-typing-dot"></span>')
                        .append('<span class="box-ai-chat-typing-dot"></span>')
                );
        }

        function addMessage(text, type, isError) {
            console.log('addMessage called:', {type: type, isError: isError, textLength: text.length});

            var messageClass = type === 'user' ? 'box-ai-chat-message-user' : 'box-ai-chat-message-ai';
            var $message = $('<div class="box-ai-chat-message ' + messageClass + '">');
            var $content = $('<div class="box-ai-chat-message-content">');

            if (isError) {
                // Error messages
                var escapedText = escapeHtml(text);
                var htmlText = escapedText.replace(/\n/g, '<br>');
                $content.html(htmlText);
                $content.addClass('box-ai-chat-message-error');
            } else if (type === 'ai' && !isError) {
                // Format AI messages with markdown-like formatting
                var formattedText = formatAIResponse(text);
                $content.html(formattedText);
            } else {
                // User messages - plain text
                $content.text(text);
            }

            $message.append($content);
            $messagesContainer.append($message);
            
            // Add smooth animation
            $message.hide().fadeIn(300);
            
            scrollToBottom();
        }

        function formatAIResponse(text) {
            console.log('Formatting AI response...');

            // Escape HTML first to prevent XSS
            var formatted = escapeHtml(text);

            // Convert markdown headings
            formatted = formatted.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
            formatted = formatted.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            formatted = formatted.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            formatted = formatted.replace(/^# (.+)$/gm, '<h1>$1</h1>');

            // Convert bold and italic
            formatted = formatted.replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>');
            formatted = formatted.replace(/\*([^*]+?)\*/g, '<em>$1</em>');

            // Convert lists
            formatted = formatted.replace(/^- (.+)$/gm, '<li>$1</li>');
            formatted = formatted.replace(/^• (.+)$/gm, '<li>$1</li>');
            formatted = formatted.replace(/^(\d+)\. (.+)$/gm, '<li>$2</li>');

            // Wrap consecutive list items in ul/ol tags
            formatted = formatted.replace(/(<li>.*<\/li>\n?)+/g, function(match) {
                return '<ul>' + match + '</ul>';
            });

            // Convert code blocks
            formatted = formatted.replace(/```([^`]+?)```/gs, '<pre><code>$1</code></pre>');
            formatted = formatted.replace(/`([^`]+?)`/g, '<code>$1</code>');

            // Convert links
            formatted = formatted.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

            // Convert paragraphs
            var paragraphs = formatted.split(/\n\n+/);
            formatted = paragraphs.map(function(p) {
                p = p.trim();
                if (p && !p.match(/^<[hpuol]/i)) {
                    return '<p>' + p + '</p>';
                }
                return p;
            }).join('\n');

            // Convert remaining line breaks
            formatted = formatted.replace(/\n/g, '<br>');

            return formatted;
        }

        function scrollToBottom() {
            $messagesContainer.animate({
                scrollTop: $messagesContainer[0].scrollHeight
            }, 300, 'swing');
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Handle input focus on mobile to prevent zoom
        if (window.innerWidth <= 768) {
            $inputField.on('focus', function() {
                // Prevent viewport zoom on iOS
                $('meta[name=viewport]').attr('content', 
                    'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover');
            });

            $inputField.on('blur', function() {
                // Re-enable zoom after input
                $('meta[name=viewport]').attr('content', 
                    'width=device-width, initial-scale=1.0, viewport-fit=cover');
            });
        }

    }); // End document.ready
})(jQuery); // End IIFE

// Global function for document viewer back button
function goBackToPage() {
    // Check if there's history to go back to
    if (window.history.length > 1 && document.referrer) {
        // Go back to previous page
        window.history.back();
    } else {
        // No history, go to homepage
        window.location.href = '/';
    }
}
