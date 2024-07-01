import React from "react";
import {Button, Fieldset, TextInput, Card, Center, Container, Grid, Space, Text, Title} from '@mantine/core';
import {CCircle} from "react-bootstrap-icons";
export function Login({changeView}){
    const onClick = () => {
        changeView('splash')
    }

    return (
        <Container>
            <Card shadow="lg" padding="lg" radius="md" withBorder style={{minWidth: '800px'}}>
                <Title order={3}>Welcome to MICA</Title>
                <Space h="sm"/>
                <Grid>
                    <Grid.Col span={7}>
                        <div>
                            <Space h="sm"/>
                            <Fieldset legend="Personal information">
                                <TextInput label="Your name" placeholder="Your name" />
                                <TextInput label="Email" placeholder="Email" mt="md" />
                                <Center>
                                    <Button mt="md" radius="md" onClick={onClick} variant="filled"
                                            color="rgba( 140, 21, 21)" style={{width: '120px'}}>
                                        Login
                                    </Button>
                                </Center>
                            </Fieldset>
                            <Space h="sm"/>
                        </div>
                    </Grid.Col>
                    <Grid.Col span={5}>
                        <Center>
                            <div className="Splash"></div>
                        </Center>
                    </Grid.Col>
                </Grid>
                <Center>
                    <Text size="xs" c="dimmed">
                            <span style={{verticalAlign:'middle'}}>
                                <CCircle/> Stanford Medicine
                            </span>
                    </Text>
                </Center>
            </Card>
        </Container>
    );
}
