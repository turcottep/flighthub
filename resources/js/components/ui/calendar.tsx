import * as React from 'react';
import { DayPicker, type DayPickerProps } from 'react-day-picker';
import 'react-day-picker/style.css';
import { cn } from '../../lib/utils';

function Calendar({
    className,
    showOutsideDays = true,
    ...props
}: DayPickerProps) {
    return (
        <DayPicker
            className={cn('calendar', className)}
            showOutsideDays={showOutsideDays}
            {...props}
        />
    );
}

Calendar.displayName = 'Calendar';

export { Calendar };
